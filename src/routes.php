<?php

/**
 * Sistema de Rutas Simple para Control de Asistencia
 * Maneja las rutas principales del sistema de forma sencilla
 */

use App\Controllers\AuthController;
use App\Controllers\AdminController;
use App\Controllers\RRHHController;
use App\Controllers\EmpleadoController;

class Router
{
    private $routes = [];

    public function __construct()
    {
        $this->definirRutas();
    }

    /**
     * Definir todas las rutas del sistema
     */
    private function definirRutas()
    {
        // Rutas de autenticación
        $this->routes['GET']['/'] = [AuthController::class, 'mostrarLogin'];
        $this->routes['GET']['/login'] = [AuthController::class, 'mostrarLogin'];
        $this->routes['POST']['/login'] = [AuthController::class, 'procesarLogin'];
        $this->routes['GET']['/logout'] = [AuthController::class, 'logout'];

        // Rutas del panel administrativo
        $this->routes['GET']['/admin'] = [AdminController::class, 'dashboard'];
        $this->routes['GET']['/admin/dashboard'] = [AdminController::class, 'dashboard'];
        $this->routes['GET']['/admin/usuarios'] = [AdminController::class, 'usuarios'];
        $this->routes['GET']['/admin/editar-usuario/{id}'] = [AdminController::class, 'editarUsuario'];
        $this->routes['GET']['/admin/dispositivos'] = [AdminController::class, 'dispositivos'];
        $this->routes['GET']['/admin/tarjetas'] = [AdminController::class, 'tarjetas'];
        $this->routes['GET']['/admin/reportes'] = [AdminController::class, 'reportes'];
        $this->routes['GET']['/admin/configuracion'] = [AdminController::class, 'configuracion'];
        $this->routes['POST']['/admin/crear-usuario'] = [AdminController::class, 'crearUsuario'];
        $this->routes['POST']['/admin/crear-dispositivo'] = [AdminController::class, 'crearDispositivo'];
        $this->routes['POST']['/admin/crear-tarjeta'] = [AdminController::class, 'crearTarjeta'];
        $this->routes['POST']['/admin/tarjetas/crear'] = [AdminController::class, 'crearTarjeta'];
        $this->routes['POST']['/admin/tarjetas/asignar'] = [AdminController::class, 'asignarTarjeta'];
        // Desasignar/bloquear/activar/eliminar cambian estado en la base de
        // datos, así que deben ser POST (con token CSRF) en vez de GET: un
        // enlace o <img> de un sitio externo puede disparar un GET del
        // navegador de la víctima sin que ella lo note (CSRF), pero no puede
        // forjar un POST con el token de sesión del admin.
        $this->routes['POST']['/admin/tarjetas/desasignar/{uid}'] = [AdminController::class, 'desasignarTarjeta'];
        $this->routes['POST']['/admin/tarjetas/bloquear/{uid}'] = [AdminController::class, 'bloquearTarjeta'];
        $this->routes['POST']['/admin/tarjetas/activar/{uid}'] = [AdminController::class, 'activarTarjeta'];
        $this->routes['POST']['/admin/tarjetas/eliminar/{uid}'] = [AdminController::class, 'eliminarTarjeta'];
        $this->routes['GET']['/admin/dispositivos/detalles/{id}'] = [AdminController::class, 'detallesDispositivo'];
        $this->routes['POST']['/admin/dispositivos/ping/{id}'] = [AdminController::class, 'pingDispositivo'];
        $this->routes['POST']['/admin/dispositivos/desactivar/{id}'] = [AdminController::class, 'desactivarDispositivo'];
        $this->routes['POST']['/admin/dispositivos/activar/{id}'] = [AdminController::class, 'activarDispositivo'];
        $this->routes['POST']['/admin/dispositivos/eliminar/{id}'] = [AdminController::class, 'eliminarDispositivo'];

        // Rutas del panel de RRHH
        $this->routes['GET']['/rrhh'] = [RRHHController::class, 'dashboard'];
        $this->routes['GET']['/rrhh/dashboard'] = [RRHHController::class, 'dashboard'];
        $this->routes['GET']['/rrhh/reportes'] = [RRHHController::class, 'reporte'];
        $this->routes['GET']['/rrhh/reporte'] = [RRHHController::class, 'reporte'];
        $this->routes['POST']['/rrhh/exportar-reporte'] = [RRHHController::class, 'exportarReporte'];
        $this->routes['GET']['/rrhh/empleado/{id}'] = [RRHHController::class, 'verEmpleado'];
        $this->routes['GET']['/rrhh/estadisticas-tiempo-real'] = [RRHHController::class, 'estadisticasTiempoReal'];

        // Rutas del panel de empleados
        $this->routes['GET']['/empleado'] = [EmpleadoController::class, 'dashboard'];
        $this->routes['GET']['/empleado/dashboard'] = [EmpleadoController::class, 'dashboard'];
        $this->routes['GET']['/empleado/historial'] = [EmpleadoController::class, 'historial'];

        // API para ESP32 (desde api/index.php)
        $this->routes['POST']['/api/asistencia'] = 'api';
        $this->routes['GET']['/api/ping'] = 'api';
    }

    /**
     * Procesar la ruta actual
     */
    public function procesarRuta()
    {
        $metodo = $_SERVER['REQUEST_METHOD'];
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // DEBUG: Mostrar información de la ruta
        if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
            echo "<!-- DEBUG ROUTER: Método=$metodo, URI original=$uri -->\n";
        }

        // Normalizar la URI removiendo el path base de XAMPP
        $uri = str_replace('/ControlDeAsistencia', '', $uri);
        $uri = str_replace('/public', '', $uri);
        $uri = rtrim($uri, '/');
        if (empty($uri)) $uri = '/';

        // DEBUG: Mostrar URI normalizada
        if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
            echo "<!-- DEBUG ROUTER: URI normalizada=$uri -->\n";
        }

        // Buscar ruta exacta
        if (isset($this->routes[$metodo][$uri])) {
            if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
                echo "<!-- DEBUG ROUTER: Ruta exacta encontrada -->\n";
            }
            $this->ejecutarControlador($this->routes[$metodo][$uri], []);
            return;
        }

        // Buscar rutas con parámetros
        foreach ($this->routes[$metodo] as $patron => $controlador) {
            $parametros = $this->coincidirRuta($patron, $uri);
            if ($parametros !== false) {
                $this->ejecutarControlador($controlador, $parametros);
                return;
            }
        }

        // Ruta no encontrada
        $this->error404();
    }

    /**
     * Verificar si una ruta coincide con un patrón
     */
    private function coincidirRuta($patron, $uri)
    {
        // Convertir patrón a regex
        $regex = preg_replace('/\{([^}]+)\}/', '([^/]+)', $patron);
        $regex = '#^' . $regex . '$#';

        if (preg_match($regex, $uri, $matches)) {
            array_shift($matches); // Remover la coincidencia completa
            return $matches;
        }

        return false;
    }

    /**
     * Ejecutar el controlador correspondiente
     */
    private function ejecutarControlador($controlador, $parametros)
    {
        if ($controlador === 'api') {
            // __DIR__ aquí es src/, así que 'api/index.php' resolvía a
            // src/api/index.php (que no existe) en vez del api/index.php
            // real que vive en la raíz del proyecto, junto a este archivo
            // (src/routes.php). Esto hacía fallar con un fatal error
            // "failed to open stream" cualquier petición del ESP32 que
            // llegara a pasar por el Router.
            require_once dirname(__DIR__) . '/api/index.php';
            return;
        }

        if (is_array($controlador)) {
            $clase = $controlador[0];
            $metodo = $controlador[1];

            // Verificar si la clase existe
            if (class_exists($clase)) {
                $instancia = new $clase();

                if (method_exists($instancia, $metodo)) {
                    call_user_func_array([$instancia, $metodo], $parametros);
                } else {
                    $this->error500("Método {$metodo} no encontrado en {$clase}");
                }
            } else {
                $this->error500("Clase {$clase} no encontrada");
            }
        } else {
            $this->error500("Controlador inválido");
        }
    }

    /**
     * Mostrar error 404
     */
    private function error404()
    {
        http_response_code(404);
        echo '<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Página no encontrada</title>
    <style>
        body { font-family: Arial, sans-serif; text-align: center; padding: 50px; background: #f5f5f5; }
        .container { max-width: 500px; margin: 0 auto; background: white; padding: 40px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #e74c3c; font-size: 48px; margin-bottom: 20px; }
        p { color: #666; margin-bottom: 30px; }
        .btn { background: #3498db; color: white; padding: 12px 24px; border: none; border-radius: 5px; text-decoration: none; display: inline-block; }
        .btn:hover { background: #2980b9; }
    </style>
</head>
<body>
    <div class="container">
        <h1>404</h1>
        <p>🚫 La página que buscas no existe</p>
        <a href="/" class="btn">🏠 Volver al inicio</a>
    </div>
</body>
</html>';
    }

    /**
     * Mostrar error 500
     */
    private function error500($mensaje = "Error interno del servidor")
    {
        http_response_code(500);
        echo '<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>500 - Error del servidor</title>
    <style>
        body { font-family: Arial, sans-serif; text-align: center; padding: 50px; background: #f5f5f5; }
        .container { max-width: 500px; margin: 0 auto; background: white; padding: 40px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #e74c3c; font-size: 48px; margin-bottom: 20px; }
        p { color: #666; margin-bottom: 30px; }
        .btn { background: #3498db; color: white; padding: 12px 24px; border: none; border-radius: 5px; text-decoration: none; display: inline-block; }
        .btn:hover { background: #2980b9; }
    </style>
</head>
<body>
    <div class="container">
        <h1>500</h1>
        <p>⚠️ ' . htmlspecialchars($mensaje) . '</p>
        <a href="/" class="btn">🏠 Volver al inicio</a>
    </div>
</body>
</html>';

        error_log("Error 500: " . $mensaje);
    }
}
