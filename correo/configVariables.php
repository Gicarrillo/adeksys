<?php
if (!defined('ACCESO_PERMITIDO')) {
    header("Location: ../index.html");
    exit();
}
function cargarEnv(string $ruta) {
    if (!file_exists($ruta)) {
        return false;
    }

    $lineas = file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lineas as $linea) {
        // Ignorar comentarios
        if (strpos(trim($linea), '#') === 0) {
            continue;
        }

        // Dividir la línea en Nombre y Valor por el primer signo '='
        list($nombre, $valor) = explode('=', $linea, 2);
        $nombre = trim($nombre);
        $valor = trim($valor);

        // Limpiar comillas
        $valor = trim($valor, '"\'');

        // Registrar en las variables de entorno de PHP
        if (!array_key_exists($nombre, $_SERVER) && !array_key_exists($nombre, $_ENV)) {
            putenv("{$nombre}={$valor}");
            $_ENV[$nombre] = $valor;
        }
    }
}

// Ejecutar la carga apuntando al archivo .env que está en la misma carpeta raíz
cargarEnv(__DIR__ . '/../.env');