<?php
/**
 * Punto de entrada principal del sistema
 * Sistema de Control de Asistencia
 */

// Configuración de errores para producción
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/php_errors.log');

// Incluir configuración
require_once __DIR__ . '/config/bootstrap.php';

// Incluir el archivo de rutas
require_once __DIR__ . '/src/routes.php';

// Este archivo tenía su propio switch/case duplicado que sólo conocía
// '/', '/login', '/admin', '/rrhh', '/empleado' y '/logout': cualquier
// otra URL (/admin/dispositivos, /admin/tarjetas, /admin/usuarios,
// /admin/reportes, /admin/configuracion, /rrhh/reportes, /api/*, etc.)
// caía siempre en el 404, sin importar que Router (definido arriba en
// src/routes.php) sí supiera manejarlas. En la práctica, todo el panel
// de gestión de dispositivos/tarjetas/usuarios, los reportes de RRHH y
// la API para el ESP32 eran inalcanzables a través de este punto de
// entrada. Se delega todo el enrutamiento a Router::procesarRuta(),
// que ya contiene (y ahora también gestiona correctamente) todas las
// rutas de la aplicación.
try {
    $router = new Router();
    $router->procesarRuta();
} catch (Exception $e) {
    // Log del error
    error_log("Error en router: " . $e->getMessage());

    http_response_code(500);

    // Mostrar detalle sólo en modo debug; en producción no se filtra el
    // mensaje de la excepción ni la traza al visitante.
    if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
        echo "<h1>Error en el sistema</h1>";
        echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<p>Archivo: " . htmlspecialchars($e->getFile()) . ":" . (int) $e->getLine() . "</p>";
        echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    } else {
        echo '<h1>500 - Error interno del servidor</h1>';
    }
}