<?php
define('ACCESO_PERMITIDO', true);
// 1. Importar la configuración del archivo .env de forma segura
require_once  __DIR__ . '/configVariables.php';

// 2. Validar que la petición venga estrictamente por el formulario (POST)
if ($_SERVER["REQUEST_METHOD"] == "POST" && !empty($_POST['nombre']) && !empty($_POST['apellidos']) && !empty($_POST['email'])) {
    
    // Sanitizar datos recibidos para evitar inyecciones de código
    $datos = [
        'nombre' => htmlspecialchars(trim($_POST['nombre'])),
        'apellidos' => htmlspecialchars(trim(($_POST['apellidos']))),
        'correo' => filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL)
    ];

    try {
        // 3. Obtener credenciales desde las variables de entorno cargadas
        $cliente_id      = getenv('OAUTH_CLIENT_ID');
        $cliente_secreto = getenv('OAUTH_CLIENT_SECRET');
        $refresh_token   = getenv('OAUTH_REFRESH_TOKEN');
        if (!$cliente_id || !$cliente_secreto || !$refresh_token) {
            throw new Exception("Faltan configuraciones críticas en el archivo .env");
        }
        // 4. Solicitar el Access Token efímero a Google mediante cURL
        $ch = curl_init("https://oauth2.googleapis.com/token");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'client_id'     => $cliente_id,
            'client_secret' => $cliente_secreto,
            'refresh_token' => $refresh_token,
            'grant_type'    => 'refresh_token',
        ]));

        $tokenRes = json_decode(curl_exec($ch), true);
        curl_close($ch);

        $accessToken = $tokenRes['access_token'] ?? null;

        if (!$accessToken) {
            throw new Exception("No se pudo generar el Access Token de Google. Revisa tus credenciales.");
        }

        // 5. Preparar el archivo PDF adjunto
        $rutaPdf =dirname(__DIR__) . '/assets/BDESCUELA.pdf'; // Ruta local de tu catálogo
        $nombreArchivo = 'Catalogo_2026.pdf'; // Nombre que verá el cliente al recibirlo

        if (file_exists($rutaPdf)) {
            $contenidoPdf = file_get_contents($rutaPdf);
            $pdfBase64 = chunk_split(base64_encode($contenidoPdf));
        } else {
            throw new Exception("El archivo PDF del catálogo no se encuentra en la ruta especificada: " . $rutaPdf);
        }

        // 6. Construir la estructura del correo en formato MIME (Multipart/Mixed)
        $boundary = md5(time());
        $from_name = getenv('EMAIL_NAME');
        $from = "=?UTF-8?B?" . base64_encode($from_name) . "?=";
        $asunto = "=?UTF-8?B?" . base64_encode("Catálogo Solicitado - Adeksys") . "?=";

        // Cuerpo del mensaje en HTML limpio
        $cuerpoHtml = "
            <div style='font-family: Arial, sans-serif; color: #333; max-width: 600px;'>
                <h2>¡Hola, {$datos['nombre']} {$datos['apellidos']}!</h2>
                <p>Gracias por tu interés en nuestro catálogo.</p>
                <p>Adjunto a este correo encontrarás el archivo PDF listo para su descarga y visualización.</p>
                <br>
                <p style='color: #777;'>Este es un correo automatizado, por favor no respondas a este mensaje.</p>
            </div>";

        // Ensamblaje del estándar MIME de correo electrónico
        $strMail ="From: {$from} <me>\r\n";
        $strMail .= "To: {$datos['correo']}\r\n";
        $strMail .= "Subject: $asunto\r\n";
        $strMail .= "MIME-Version: 1.0\r\n";
        $strMail .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n\r\n";

        // Sección 1: El texto HTML del cuerpo
        $strMail .= "--{$boundary}\r\n";
        $strMail .= "Content-Type: text/html; charset=UTF-8\r\n";
        $strMail .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
        $strMail .= $cuerpoHtml . "\r\n\r\n";

        // Sección 2: El archivo PDF adjunto
        $strMail .= "--{$boundary}\r\n";
        $strMail .= "Content-Type: application/pdf; name=\"{$nombreArchivo}\"\r\n";
        $strMail .= "Content-Transfer-Encoding: base64\r\n";
        $strMail .= "Content-Disposition: attachment; filename=\"{$nombreArchivo}\"\r\n\r\n";
        $strMail .= $pdfBase64 . "\r\n\r\n";

        // Fin del multipart
        $strMail .= "--{$boundary}--";

        // 7. Codificar el correo completo al formato Web-Safe Base64 exigido por Google API
        $mimeListo = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($strMail));

        // 8. Enviar el paquete a la API de Gmail por HTTP POST
        $ch = curl_init("https://www.googleapis.com/gmail/v1/users/me/messages/send");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $accessToken", 
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['raw' => $mimeListo]));
        
        $respuestaGmail = curl_exec($ch);
        curl_close($ch);

        $resDecoded = json_decode($respuestaGmail, true);

        // 9. Confirmación de envío al Frontend
        if (isset($resDecoded['id'])) {
            echo "<script>
                    alert('¡Catálogo enviado con éxito! Revisa tu bandeja de entrada.');
                    window.location.href='../index.html';
                  </script>";
        } else {
            throw new Exception("Gmail API rechazó el mensaje: " . $respuestaGmail);
        }

    } catch (Exception $e) {
        // En caso de error, muestra una alerta y te regresa al index sin tumbar la pantalla
        echo "<script>
                alert('Hubo un problema: " . addslashes($e->getMessage()) . "');
                window.location.href='../index.html';
              </script>";
    }

} else {
    // Redirección de seguridad si intentan acceder al archivo escribiendo la URL directamente
    header("Location: ../index.html");
    exit();
}