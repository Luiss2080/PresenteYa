<?php
/**
 * Clase simple para manejo de autenticación
 * Sistema de Control de Asistencia
 */

namespace App\Utils;

use App\Models\Database;

class Auth {
    private static $usuario = null;

    /**
     * Inicializar sesión
     */
    public static function iniciar() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Verificar si hay un usuario en sesión
        if (isset($_SESSION['usuario_id'])) {
            self::cargarUsuario($_SESSION['usuario_id']);
        }
    }

    /**
     * Intentar login
     */
    public static function login($email, $password) {
        $db = Database::getInstance();
        
        // Buscar usuario
        $sql = "SELECT * FROM usuarios WHERE email = ? AND activo = 1";
        $usuario = $db->fetch($sql, [$email]);
        
        if (!$usuario) {
            return ['success' => false, 'message' => 'Usuario no encontrado'];
        }

        // Verificar contraseña
        if (!password_verify($password, $usuario['password_hash'])) {
            return ['success' => false, 'message' => 'Contraseña incorrecta'];
        }

        // Establecer sesión
        self::establecerSesion($usuario);
        
        // Actualizar último login
        $db->query("UPDATE usuarios SET ultimo_login = NOW() WHERE id = ?", [$usuario['id']]);

        return ['success' => true, 'message' => 'Login exitoso', 'usuario' => $usuario];
    }

    /**
     * Cerrar sesión
     */
    public static function logout() {
        $_SESSION = [];
        session_destroy();
        self::$usuario = null;
    }

    /**
     * Verificar si el usuario está autenticado
     */
    public static function estaAutenticado() {
        return isset($_SESSION['usuario_id']);
    }

    /**
     * Obtener usuario actual
     */
    public static function usuario() {
        return self::$usuario;
    }

    /**
     * Verificar si el usuario tiene un rol específico
     */
    public static function tieneRol($rol) {
        return isset($_SESSION['usuario_rol']) && $_SESSION['usuario_rol'] === $rol;
    }

    /**
     * Determinar si la petición actual es una petición AJAX/JSON.
     *
     * Usado por AuthMiddleware para decidir si debe responder con un
     * redirect (navegación normal) o con un JSON + código HTTP (fetch/AJAX).
     * Antes no existía este método aunque AuthMiddleware ya lo invocaba,
     * lo que provocaba un "Call to undefined method" fatal en cuanto se
     * usara el middleware.
     */
    public static function esAjax() {
        $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        if (strtolower($requestedWith) === 'xmlhttprequest') {
            return true;
        }

        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return stripos($accept, 'application/json') !== false;
    }

    /**
     * Requerir autenticación
     */
    public static function requerir() {
        if (!self::estaAutenticado()) {
            header('Location: /ControlDeAsistencia/');
            exit;
        }
    }

    /**
     * Requerir rol específico.
     *
     * Los roles se almacenan en la base de datos y en sesión con los
     * valores cortos 'admin', 'rrhh' y 'empleado' (ver
     * AuthController::redirigirSegunRol y la columna `usuarios.rol`).
     * Este mapa normaliza alias equivalentes (p. ej. 'administrador')
     * para que quien llame a requerirRol() con cualquiera de las dos
     * formas obtenga el resultado correcto.
     */
    private static $aliasRoles = [
        'administrador' => 'admin',
    ];

    public static function requerirRol($rol) {
        self::requerir();

        $rol = self::$aliasRoles[$rol] ?? $rol;

        if (!self::tieneRol($rol)) {
            header('Location: /ControlDeAsistencia/?error=' . urlencode('Acceso denegado'));
            exit;
        }
    }

    /**
     * Generar token CSRF
     */
    public static function generarTokenCSRF() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Verificar token CSRF
     */
    public static function verificarTokenCSRF($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }

    // Métodos privados

    private static function cargarUsuario($userId) {
        $db = Database::getInstance();
        $sql = "SELECT * FROM usuarios WHERE id = ?";
        self::$usuario = $db->fetch($sql, [$userId]);
    }

    private static function establecerSesion($usuario) {
        session_regenerate_id(true);
        
        $_SESSION['usuario_id'] = $usuario['id'];
        $_SESSION['usuario_email'] = $usuario['email'];
        $_SESSION['usuario_rol'] = $usuario['rol'];
        $_SESSION['usuario_nombre'] = $usuario['nombres'] . ' ' . $usuario['apellidos'];
        
        self::$usuario = $usuario;
    }
}
?>