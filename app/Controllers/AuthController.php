<?php
/**
 * Controlador de Autenticación Simplificado
 * Sistema de Control de Asistencia
 */

namespace App\Controllers;

use App\Models\Database;
use App\Utils\Auth;

class AuthController {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Mostrar formulario de login
     */
    public function mostrarLogin() {
        // Si ya está autenticado, redirigir al dashboard
        if (isset($_SESSION['usuario_id'])) {
            $this->redirigirSegunRol();
            return;
        }

        // Variables para la vista
        $titulo = 'Iniciar Sesión - Sistema de Asistencia';
        // Usar el mismo generador/almacén que Auth::verificarTokenCSRF()
        // para que procesarLogin() pueda validar este mismo token.
        $csrf_token = Auth::generarTokenCSRF();

        // Incluir vista de login
        include __DIR__ . '/../Views/auth/login.php';
    }

    /**
     * Procesar login
     */
    public function procesarLogin() {
        // El formulario de login ya incluía un campo csrf_token, pero nunca
        // se comprobaba aquí: cualquier sitio externo podía enviar un POST
        // a /login en nombre de un visitante (CSRF de login / "login
        // fixation"). Se valida contra el token guardado en sesión antes de
        // procesar nada.
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Auth::verificarTokenCSRF($csrfToken)) {
            header('Location: /ControlDeAsistencia/?error=' . urlencode('Sesión de formulario expirada, intenta de nuevo'));
            exit;
        }

        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            header('Location: /ControlDeAsistencia/?error=' . urlencode('Email y contraseña son requeridos'));
            exit;
        }

        // Buscar usuario en la base de datos usando solo el rol simple
        $sql = "SELECT * FROM usuarios WHERE email = ? AND activo = 1";
        $usuario = $this->db->fetch($sql, [$email]);

        if (!$usuario) {
            header('Location: /ControlDeAsistencia/?error=' . urlencode('Usuario no encontrado'));
            exit;
        }

        // Verificar contraseña
        if (!password_verify($password, $usuario['password_hash'])) {
            header('Location: /ControlDeAsistencia/?error=' . urlencode('Contraseña incorrecta'));
            exit;
        }

        // Establecer sesión simple
        $_SESSION['usuario_id'] = $usuario['id'];
        $_SESSION['usuario_email'] = $usuario['email'];
        $_SESSION['usuario_rol'] = $usuario['rol'];
        $_SESSION['usuario'] = [
            'id' => $usuario['id'],
            'nombres' => $usuario['nombres'],
            'apellidos' => $usuario['apellidos'],
            'email' => $usuario['email'],
            'rol' => $usuario['rol']
        ];

        // Actualizar último login
        $this->db->query("UPDATE usuarios SET ultimo_login = NOW() WHERE id = ?", [$usuario['id']]);

        // Redirigir según rol
        $this->redirigirSegunRol();
    }

    /**
     * Cerrar sesión
     */
    public function logout() {
        session_destroy();
        header('Location: /ControlDeAsistencia/?mensaje=' . urlencode('Sesión cerrada correctamente'));
        exit;
    }

    /**
     * Redirigir según el rol del usuario
     */
    private function redirigirSegunRol() {
        $rol = $_SESSION['usuario_rol'] ?? '';
        
        switch ($rol) {
            case 'admin':
                header('Location: /ControlDeAsistencia/admin');
                break;
            case 'rrhh':
                header('Location: /ControlDeAsistencia/rrhh');
                break;
            case 'empleado':
                header('Location: /ControlDeAsistencia/empleado');
                break;
            default:
                header('Location: /ControlDeAsistencia/?error=' . urlencode('Rol no válido'));
        }
        exit;
    }

    /**
     * Verificar si el usuario está autenticado
     */
    public static function estaAutenticado() {
        return isset($_SESSION['usuario_id']);
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
     * Requerir rol específico
     */
    public static function requerirRol($rol) {
        self::requerir();
        
        if ($_SESSION['usuario_rol'] !== $rol) {
            header('Location: /ControlDeAsistencia/?error=' . urlencode('Acceso denegado'));
            exit;
        }
    }
}
?>