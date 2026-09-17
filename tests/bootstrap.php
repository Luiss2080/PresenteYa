<?php

/**
 * Bootstrap de PHPUnit.
 *
 * Deliberadamente NO carga config/bootstrap.php: ese archivo arranca una
 * sesión PHP, define headers de seguridad y espera un entorno HTTP/.env
 * real, nada de lo cual es necesario (ni deseable) para correr pruebas
 * unitarias puras en CI. En su lugar registramos un autoloader mínimo,
 * igual de simple que el de config/bootstrap.php, sólo para el namespace
 * App\.
 */

spl_autoload_register(function ($class) {
    if (strpos($class, 'App\\') !== 0) {
        return false;
    }

    $relative = substr($class, strlen('App\\'));
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, $relative);

    $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . $relative . '.php';

    if (file_exists($file)) {
        require_once $file;
        return true;
    }

    return false;
});
