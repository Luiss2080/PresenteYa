<?php

/**
 * Controlador de Administración
 * Sistema de Control de Asistencia
 */

namespace App\Controllers;

use App\Models\Database;
use App\Utils\Auth;
use Exception;

class AdminController
{
    private $db;

    public function __construct()
    {
        try {
            $this->db = Database::getInstance();
        } catch (Exception $e) {
            error_log("Error conectando base de datos en AdminController: " . $e->getMessage());
            $_SESSION['error'] = 'Error de conexión a la base de datos';
            header('Location: /ControlDeAsistencia/?error=' . urlencode('Error de sistema'));
            exit;
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Verificar autenticación y rol de administrador
        if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_rol'] !== 'admin') {
            header('Location: /ControlDeAsistencia/?error=' . urlencode('Acceso denegado'));
            exit;
        }
    }

    /**
     * Dashboard principal de administración
     */
    public function dashboard()
    {
        try {
            // Obtener estadísticas del sistema
            $stats = $this->obtenerEstadisticas();

            // Obtener dispositivos activos con valores por defecto seguros
            $dispositivos_activos = [];
            try {
                $dispositivos_activos = $this->db->fetchAll("
                    SELECT d.*, 
                           CASE 
                               WHEN d.ultimo_ping > DATE_SUB(NOW(), INTERVAL 5 MINUTE) THEN 'online'
                               WHEN d.ultimo_ping > DATE_SUB(NOW(), INTERVAL 30 MINUTE) THEN 'warning'
                               ELSE 'offline'
                           END as status_conexion,
                           d.ultimo_ping as ultima_conexion
                    FROM dispositivos d 
                    WHERE d.estado = 'activo' 
                    ORDER BY d.nombre
                ") ?? [];
            } catch (Exception $e) {
                error_log("Error obteniendo dispositivos activos: " . $e->getMessage());
                $dispositivos_activos = [];
            }

            // Obtener actividad reciente
            $actividad_reciente = $this->obtenerActividadReciente();

            // Obtener información del usuario logueado
            $usuario = $this->obtenerUsuarioLogueado();

            // Renderizar vista con layout
            $this->renderViewWithLayout('admin/dashboard', [
                'usuario' => $usuario,
                'titulo' => 'Panel de Administración',
                'seccion' => 'Dashboard',
                'stats' => $stats,
                'dispositivos_activos' => $dispositivos_activos,
                'actividad_reciente' => $actividad_reciente
            ]);
        } catch (Exception $e) {
            error_log("Error en dashboard admin: " . $e->getMessage());
            $_SESSION['error'] = 'Error al cargar el dashboard';
            header('Location: /ControlDeAsistencia/?error=' . urlencode('Error interno del sistema'));
            exit;
        }
    }

    /**
     * Gestión de usuarios
     */
    public function usuarios()
    {
        try {
            $filtros = [
                'search' => $_GET['search'] ?? '',
                'rol' => $_GET['rol'] ?? ''
            ];

            $usuarios = $this->buscarUsuarios($filtros);

            // Asegurar que las variables estén definidas para la vista
            if (!isset($usuarios)) {
                $usuarios = [];
            }

            // Obtener usuario logueado
            $usuario = $this->obtenerUsuarioLogueado();

            $this->renderViewWithLayout('admin/usuarios', [
                'usuario' => $usuario,
                'titulo' => 'Gestión de Usuarios',
                'seccion' => 'Usuarios',
                'usuarios' => $usuarios,
                'filtros' => $filtros
            ]);
        } catch (Exception $e) {
            error_log("Error en usuarios admin: " . $e->getMessage());
            $_SESSION['error'] = 'Error al cargar usuarios';
            header('Location: /ControlDeAsistencia/admin');
            exit;
        }
    }

    /**
     * Crear nuevo usuario
     */
    public function crearUsuario()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->verificarCSRF();
            try {
                $datos = $this->validarDatosUsuario($_POST);

                // Verificar que el email no exista
                $existeEmail = $this->db->fetch("SELECT id FROM usuarios WHERE email = ?", [$datos['email']]);
                if ($existeEmail) {
                    throw new \Exception('El email ya está registrado');
                }

                // Verificar que el número de empleado no exista
                $existeNumero = $this->db->fetch("SELECT id FROM usuarios WHERE numero_empleado = ?", [$datos['numero_empleado']]);
                if ($existeNumero) {
                    throw new \Exception('El número de empleado ya está registrado');
                }

                // Encriptar contraseña
                $datos['password_hash'] = password_hash($datos['password'], PASSWORD_DEFAULT);
                unset($datos['password']);

                // Crear usuario
                $usuario_id = $this->db->insert('usuarios', $datos);

                if ($usuario_id) {
                    $_SESSION['mensaje'] = 'Usuario creado exitosamente';
                    header('Location: /ControlDeAsistencia/admin/usuarios');
                    exit;
                } else {
                    throw new \Exception('Error al crear el usuario');
                }
            } catch (\Exception $e) {
                $_SESSION['error'] = $e->getMessage();
            }
        }

        include __DIR__ . '/../Views/admin/crear_usuario.php';
    }

    /**
     * Gestión de dispositivos ESP32
     */
    public function dispositivos()
    {
        try {
            $dispositivos = $this->db->fetchAll("
                SELECT d.*, 
                       COUNT(a.id) as total_registros,
                       MAX(a.fecha_hora) as ultimo_registro
                FROM dispositivos d
                LEFT JOIN asistencias a ON d.id = a.dispositivo_id
                WHERE d.estado = 'activo'
                GROUP BY d.id
                ORDER BY d.nombre
            ");

            // Obtener usuario logueado
            $usuario = $this->obtenerUsuarioLogueado();

            $this->renderViewWithLayout('admin/dispositivos', [
                'usuario' => $usuario,
                'titulo' => 'Gestión de Dispositivos',
                'seccion' => 'Dispositivos',
                'dispositivos' => $dispositivos
            ]);
        } catch (Exception $e) {
            error_log("Error en dispositivos admin: " . $e->getMessage());
            $_SESSION['error'] = 'Error al cargar dispositivos';
            header('Location: /ControlDeAsistencia/admin');
            exit;
        }
    }

    /**
     * Crear nuevo dispositivo
     */
    public function crearDispositivo()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->verificarCSRF();
            try {
                $datos = [
                    'nombre' => trim($_POST['nombre']),
                    'token_dispositivo' => bin2hex(random_bytes(32)),
                    'ubicacion' => trim($_POST['ubicacion']),
                    'estado' => 'activo'
                ];

                if (empty($datos['nombre']) || empty($datos['ubicacion'])) {
                    throw new \Exception('Nombre y ubicación son obligatorios');
                }

                $dispositivo_id = $this->db->insert('dispositivos', $datos);

                if ($dispositivo_id) {
                    $_SESSION['mensaje'] = 'Dispositivo creado exitosamente. Token: ' . $datos['token_dispositivo'];
                } else {
                    throw new \Exception('Error al crear el dispositivo');
                }
            } catch (\Exception $e) {
                $_SESSION['error'] = $e->getMessage();
            }
        }

        header('Location: /ControlDeAsistencia/admin/dispositivos');
        exit;
    }

    /**
     * Gestión de tarjetas RFID
     */
    public function tarjetas()
    {
        try {
            // Obtener filtros
            $filtros = [
                'estado' => $_GET['estado'] ?? '',
                'asignacion' => $_GET['asignacion'] ?? '',
                'buscar' => $_GET['buscar'] ?? ''
            ];

            // Base de la consulta
            $sql = "
                SELECT t.*, 
                       u.nombres, u.apellidos, u.numero_empleado,
                       (SELECT MAX(fecha_hora) FROM asistencias a WHERE a.tarjeta_uid = t.uid_tarjeta) as ultimo_uso
                FROM tarjetas_rfid t
                LEFT JOIN usuarios u ON t.usuario_id = u.id
                WHERE 1=1
            ";

            $params = [];

            // Aplicar filtros
            if (!empty($filtros['estado'])) {
                $sql .= " AND t.estado = ?";
                $params[] = $filtros['estado'];
            }

            if (!empty($filtros['asignacion'])) {
                if ($filtros['asignacion'] === 'asignadas') {
                    $sql .= " AND t.usuario_id IS NOT NULL";
                } elseif ($filtros['asignacion'] === 'sin_asignar') {
                    $sql .= " AND t.usuario_id IS NULL";
                }
            }

            if (!empty($filtros['buscar'])) {
                $sql .= " AND (t.uid_tarjeta LIKE ? OR u.nombres LIKE ? OR u.apellidos LIKE ? OR u.numero_empleado LIKE ?)";
                $busqueda = '%' . $filtros['buscar'] . '%';
                $params = array_merge($params, [$busqueda, $busqueda, $busqueda, $busqueda]);
            }

            $sql .= " ORDER BY t.fecha_asignacion DESC";

            $tarjetas = $this->db->fetchAll($sql, $params);

            // Obtener usuarios disponibles para asignar
            $usuarios_disponibles = $this->db->fetchAll("
                SELECT u.id, u.nombres, u.apellidos, u.numero_empleado
                FROM usuarios u
                LEFT JOIN tarjetas_rfid t ON u.id = t.usuario_id AND t.estado = 'activa'
                WHERE u.activo = 1 AND t.id IS NULL
                ORDER BY u.apellidos, u.nombres
            ");

            // Obtener usuario logueado
            $usuario = $this->obtenerUsuarioLogueado();

            $this->renderViewWithLayout('admin/tarjetas', [
                'usuario' => $usuario,
                'titulo' => 'Gestión de Tarjetas RFID',
                'seccion' => 'Tarjetas',
                'tarjetas' => $tarjetas,
                'usuarios_disponibles' => $usuarios_disponibles,
                'filtros' => $filtros
            ]);
        } catch (Exception $e) {
            error_log("Error en tarjetas admin: " . $e->getMessage());
            $_SESSION['error'] = 'Error al cargar tarjetas';
            header('Location: /ControlDeAsistencia/admin');
            exit;
        }
    }

    /**
     * Asignar tarjeta RFID a usuario
     */
    public function asignarTarjeta()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->verificarCSRF();
            try {
                $datos = [
                    'uid_tarjeta' => strtoupper(trim($_POST['uid_tarjeta'])),
                    'usuario_id' => (int)$_POST['usuario_id'],
                    'estado' => 'activa'
                ];

                if (empty($datos['uid_tarjeta']) || empty($datos['usuario_id'])) {
                    throw new \Exception('UID de tarjeta y usuario son obligatorios');
                }

                // Verificar que el UID no exista
                $tarjeta_existente = $this->db->fetch("SELECT id FROM tarjetas_rfid WHERE uid_tarjeta = ?", [$datos['uid_tarjeta']]);
                if ($tarjeta_existente) {
                    throw new \Exception('El UID de la tarjeta ya está registrado');
                }

                // Verificar que el usuario no tenga otra tarjeta activa
                $tarjeta_usuario = $this->db->fetch("SELECT id FROM tarjetas_rfid WHERE usuario_id = ? AND estado = 'activa'", [$datos['usuario_id']]);
                if ($tarjeta_usuario) {
                    throw new \Exception('El usuario ya tiene una tarjeta activa asignada');
                }

                $tarjeta_id = $this->db->insert('tarjetas_rfid', $datos);

                if ($tarjeta_id) {
                    $_SESSION['mensaje'] = 'Tarjeta RFID asignada exitosamente';
                } else {
                    throw new \Exception('Error al asignar la tarjeta RFID');
                }
            } catch (\Exception $e) {
                $_SESSION['error'] = $e->getMessage();
            }
        }

        header('Location: /ControlDeAsistencia/admin/tarjetas');
        exit;
    }

    /**
     * Reportes administrativos
     */
    public function reportes()
    {
        try {
            $filtros = [
                'fecha_inicio' => $_GET['fecha_inicio'] ?? date('Y-m-01'),
                'fecha_fin' => $_GET['fecha_fin'] ?? date('Y-m-d'),
                'usuario' => $_GET['usuario'] ?? ''
            ];

            $reporte_data = $this->generarDatosReporte($filtros);
            $usuarios = $this->db->fetchAll("SELECT id, nombres, apellidos, numero_empleado FROM usuarios WHERE activo = 1 ORDER BY apellidos, nombres");

            include __DIR__ . '/../Views/admin/reportes.php';
        } catch (Exception $e) {
            error_log("Error en reportes admin: " . $e->getMessage());
            $_SESSION['error'] = 'Error al cargar reportes';
            header('Location: /ControlDeAsistencia/admin');
            exit;
        }
    }

    /**
     * Configuración del sistema
     */
    public function configuracion()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->verificarCSRF();
            try {
                $configuraciones = $_POST['config'] ?? [];

                foreach ($configuraciones as $clave => $valor) {
                    $this->db->query("
                        INSERT INTO configuracion_sistema (clave, valor, updated_at) 
                        VALUES (?, ?, NOW())
                        ON DUPLICATE KEY UPDATE valor = VALUES(valor), updated_at = NOW()
                    ", [$clave, $valor]);
                }

                $_SESSION['mensaje'] = 'Configuración actualizada exitosamente';
            } catch (\Exception $e) {
                $_SESSION['error'] = 'Error al actualizar la configuración: ' . $e->getMessage();
            }
        }

        try {
            // Obtener configuraciones actuales
            $configuraciones = [];
            $configs = $this->db->fetchAll("SELECT clave, valor FROM configuracion_sistema");
            foreach ($configs as $config) {
                $configuraciones[$config['clave']] = $config['valor'];
            }

            include __DIR__ . '/../Views/admin/configuracion.php';
        } catch (Exception $e) {
            error_log("Error en configuración admin: " . $e->getMessage());
            $_SESSION['error'] = 'Error al cargar configuración';
            header('Location: /ControlDeAsistencia/admin');
            exit;
        }
    }

    /**
     * Métodos auxiliares privados
     */

    /**
     * Obtener estadísticas del sistema
     */
    private function obtenerEstadisticas()
    {
        $stats = [
            'total_usuarios' => 0,
            'dispositivos_activos' => 0,
            'tarjetas_activas' => 0,
            'marcaciones_hoy' => 0
        ];

        try {
            // Total usuarios activos
            $result = $this->db->fetch("SELECT COUNT(*) as total FROM usuarios WHERE activo = 1");
            $stats['total_usuarios'] = $result['total'] ?? 0;
        } catch (Exception $e) {
            error_log("Error contando usuarios: " . $e->getMessage());
        }

        try {
            // Dispositivos activos
            $result = $this->db->fetch("SELECT COUNT(*) as total FROM dispositivos WHERE estado = 'activo'");
            $stats['dispositivos_activos'] = $result['total'] ?? 0;
        } catch (Exception $e) {
            error_log("Error contando dispositivos: " . $e->getMessage());
        }

        try {
            // Tarjetas activas
            $result = $this->db->fetch("SELECT COUNT(*) as total FROM tarjetas_rfid WHERE estado = 'activa' AND usuario_id IS NOT NULL");
            $stats['tarjetas_activas'] = $result['total'] ?? 0;
        } catch (Exception $e) {
            error_log("Error contando tarjetas: " . $e->getMessage());
        }

        try {
            // Registros de hoy
            $result = $this->db->fetch("SELECT COUNT(*) as total FROM asistencias WHERE DATE(fecha_hora) = CURDATE()");
            $stats['marcaciones_hoy'] = $result['total'] ?? 0;
        } catch (Exception $e) {
            error_log("Error contando registros de hoy: " . $e->getMessage());
        }

        return $stats;
    }

    /**
     * Obtener actividad reciente
     */
    private function obtenerActividadReciente()
    {
        try {
            return $this->db->fetchAll("
                SELECT 
                    CONCAT(u.nombres, ' ', u.apellidos) as usuario_nombre,
                    a.tipo,
                    a.fecha_hora,
                    d.nombre as dispositivo,
                    d.ubicacion
                FROM asistencias a
                LEFT JOIN usuarios u ON a.usuario_id = u.id
                LEFT JOIN dispositivos d ON a.dispositivo_id = d.id
                ORDER BY a.fecha_hora DESC
                LIMIT 10
            ") ?? [];
        } catch (Exception $e) {
            error_log("Error obteniendo actividad reciente: " . $e->getMessage());
            return [];
        }
    }

    private function validarDatosUsuario($datos)
    {
        $errores = [];

        if (empty(trim($datos['nombres']))) {
            $errores[] = 'El nombre es obligatorio';
        }

        if (empty(trim($datos['apellidos']))) {
            $errores[] = 'Los apellidos son obligatorios';
        }

        if (empty(trim($datos['email'])) || !filter_var($datos['email'], FILTER_VALIDATE_EMAIL)) {
            $errores[] = 'El email es obligatorio y debe ser válido';
        }

        if (empty(trim($datos['numero_empleado']))) {
            $errores[] = 'El número de empleado es obligatorio';
        }

        if (empty($datos['password']) || strlen($datos['password']) < 6) {
            $errores[] = 'La contraseña es obligatoria y debe tener al menos 6 caracteres';
        }

        if (!empty($errores)) {
            throw new \Exception(implode(', ', $errores));
        }

        return [
            'nombres' => trim($datos['nombres']),
            'apellidos' => trim($datos['apellidos']),
            'email' => trim(strtolower($datos['email'])),
            'numero_empleado' => trim($datos['numero_empleado']),
            'telefono' => trim($datos['telefono'] ?? ''),
            'puesto' => trim($datos['puesto'] ?? ''),
            'rol' => $datos['rol'] ?? 'empleado',
            'fecha_ingreso' => !empty($datos['fecha_ingreso']) ? $datos['fecha_ingreso'] : date('Y-m-d'),
            'password' => $datos['password']
        ];
    }

    private function buscarUsuarios($filtros)
    {
        $sql = "SELECT * FROM usuarios WHERE activo = 1";
        $params = [];

        if (!empty($filtros['search'])) {
            $sql .= " AND (nombres LIKE ? OR apellidos LIKE ? OR numero_empleado LIKE ? OR email LIKE ?)";
            $search = '%' . $filtros['search'] . '%';
            $params = array_merge($params, [$search, $search, $search, $search]);
        }

        if (!empty($filtros['rol'])) {
            $sql .= " AND rol = ?";
            $params[] = $filtros['rol'];
        }

        $sql .= " ORDER BY apellidos, nombres";

        return $this->db->fetchAll($sql, $params);
    }

    private function generarDatosReporte($filtros)
    {
        $sql = "
            SELECT 
                u.numero_empleado,
                CONCAT(u.nombres, ' ', u.apellidos) as nombre_completo,
                u.puesto,
                DATE(a.fecha_hora) as fecha,
                MIN(CASE WHEN a.tipo = 'entrada' THEN a.fecha_hora END) as primera_entrada,
                MAX(CASE WHEN a.tipo = 'salida' THEN a.fecha_hora END) as ultima_salida,
                COUNT(CASE WHEN a.tipo = 'entrada' THEN 1 END) as total_entradas,
                COUNT(CASE WHEN a.tipo = 'salida' THEN 1 END) as total_salidas,
                SUM(a.es_tardanza) as tardanzas
            FROM usuarios u
            LEFT JOIN asistencias a ON u.id = a.usuario_id
            WHERE u.activo = 1
        ";

        $params = [];

        if (!empty($filtros['fecha_inicio']) && !empty($filtros['fecha_fin'])) {
            $sql .= " AND DATE(a.fecha_hora) BETWEEN ? AND ?";
            $params[] = $filtros['fecha_inicio'];
            $params[] = $filtros['fecha_fin'];
        }

        if (!empty($filtros['usuario'])) {
            $sql .= " AND u.id = ?";
            $params[] = $filtros['usuario'];
        }

        $sql .= " GROUP BY u.id, DATE(a.fecha_hora) ORDER BY u.apellidos, u.nombres, fecha DESC";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Obtiene información del usuario logueado
     */
    private function obtenerUsuarioLogueado()
    {
        if (!isset($_SESSION['usuario_id'])) {
            return null;
        }

        try {
            $sql = "SELECT * FROM usuarios WHERE id = ?";
            $usuario = $this->db->fetch($sql, [$_SESSION['usuario_id']]);

            if ($usuario) {
                return [
                    'id' => $usuario['id'],
                    'numero_empleado' => $usuario['numero_empleado'],
                    'nombre' => $usuario['nombres'] . ' ' . $usuario['apellidos'],
                    'email' => $usuario['email'],
                    'rol' => $usuario['rol'],
                    'activo' => $usuario['activo']
                ];
            }
        } catch (\Exception $e) {
            error_log("Error obteniendo usuario logueado: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Renderiza una vista usando el layout principal
     */
    private function renderViewWithLayout($viewPath, $data = [])
    {
        // Todas las vistas administrativas reciben un token CSRF listo para
        // usar en sus formularios (ver verificarCSRF()).
        if (!isset($data['csrf_token'])) {
            $data['csrf_token'] = Auth::generarTokenCSRF();
        }

        // Extraer variables para que estén disponibles en las vistas
        extract($data);

        // Capturar el contenido de la vista
        ob_start();
        include __DIR__ . '/../Views/' . $viewPath . '.php';
        $contenido = ob_get_clean();

        // Incluir el layout principal
        include __DIR__ . '/../Views/layouts/main.php';
    }

    /**
     * Verificar el token CSRF de una petición que modifica estado.
     *
     * Ninguno de los formularios/acciones POST de este panel (crear
     * usuario, crear/asignar tarjeta, crear dispositivo, bloquear/activar/
     * eliminar tarjeta o dispositivo, configuración) validaba un token
     * CSRF, por lo que cualquier sitio externo podía forjar una petición
     * que un administrador autenticado ejecutara sin darse cuenta
     * (ej. un <form> oculto que se auto-envía a
     * /admin/tarjetas/eliminar/{uid}). Se corta la petición con 403 si el
     * token no coincide con el guardado en sesión.
     */
    private function verificarCSRF()
    {
        $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

        if (!Auth::verificarTokenCSRF($token)) {
            http_response_code(403);
            $_SESSION['error'] = 'Token de seguridad inválido o expirado. Vuelve a intentarlo.';

            $referer = $_SERVER['HTTP_REFERER'] ?? '/ControlDeAsistencia/admin';
            header('Location: ' . $referer);
            exit;
        }
    }

    /**
     * Crear nueva tarjeta RFID
     */
    public function crearTarjeta()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $uid = strtoupper(trim($_POST['uid_tarjeta']));
                $usuario_id = !empty($_POST['usuario_id']) ? (int)$_POST['usuario_id'] : null;
                $descripcion = trim($_POST['descripcion'] ?? '');

                // Verificar que no existe la tarjeta
                $existe = $this->db->fetch("SELECT id FROM tarjetas_rfid WHERE uid_tarjeta = ?", [$uid]);
                if ($existe) {
                    $_SESSION['error'] = 'La tarjeta ya está registrada';
                    header('Location: /ControlDeAsistencia/admin/tarjetas');
                    exit;
                }

                // Insertar tarjeta
                $resultado = $this->db->insert('tarjetas_rfid', [
                    'uid_tarjeta' => $uid,
                    'usuario_id' => $usuario_id,
                    'estado' => 'activa',
                    'descripcion' => $descripcion,
                    'fecha_asignacion' => $usuario_id ? date('Y-m-d H:i:s') : null
                ]);

                if ($resultado) {
                    $_SESSION['success'] = 'Tarjeta registrada exitosamente';
                } else {
                    $_SESSION['error'] = 'Error al registrar la tarjeta';
                }
            } catch (Exception $e) {
                error_log("Error creando tarjeta: " . $e->getMessage());
                $_SESSION['error'] = 'Error interno al crear la tarjeta';
            }
        }

        header('Location: /ControlDeAsistencia/admin/tarjetas');
        exit;
    }

    /**
     * Desasignar tarjeta
     */
    public function desasignarTarjeta($uid)
    {
        $this->verificarCSRF();
        try {
            $resultado = $this->db->update(
                'tarjetas_rfid',
                ['usuario_id' => null, 'fecha_asignacion' => null],
                ['uid_tarjeta' => $uid]
            );

            if ($resultado) {
                $_SESSION['success'] = 'Tarjeta desasignada exitosamente';
            } else {
                $_SESSION['error'] = 'Error al desasignar la tarjeta';
            }
        } catch (Exception $e) {
            error_log("Error desasignando tarjeta: " . $e->getMessage());
            $_SESSION['error'] = 'Error interno al desasignar la tarjeta';
        }

        header('Location: /ControlDeAsistencia/admin/tarjetas');
        exit;
    }

    /**
     * Bloquear tarjeta
     */
    public function bloquearTarjeta($uid)
    {
        $this->verificarCSRF();
        try {
            $resultado = $this->db->update(
                'tarjetas_rfid',
                ['estado' => 'bloqueada'],
                ['uid_tarjeta' => $uid]
            );

            if ($resultado) {
                $_SESSION['success'] = 'Tarjeta bloqueada exitosamente';
            } else {
                $_SESSION['error'] = 'Error al bloquear la tarjeta';
            }
        } catch (Exception $e) {
            error_log("Error bloqueando tarjeta: " . $e->getMessage());
            $_SESSION['error'] = 'Error interno al bloquear la tarjeta';
        }

        header('Location: /ControlDeAsistencia/admin/tarjetas');
        exit;
    }

    /**
     * Activar tarjeta
     */
    public function activarTarjeta($uid)
    {
        $this->verificarCSRF();
        try {
            $resultado = $this->db->update(
                'tarjetas_rfid',
                ['estado' => 'activa'],
                ['uid_tarjeta' => $uid]
            );

            if ($resultado) {
                $_SESSION['success'] = 'Tarjeta activada exitosamente';
            } else {
                $_SESSION['error'] = 'Error al activar la tarjeta';
            }
        } catch (Exception $e) {
            error_log("Error activando tarjeta: " . $e->getMessage());
            $_SESSION['error'] = 'Error interno al activar la tarjeta';
        }

        header('Location: /ControlDeAsistencia/admin/tarjetas');
        exit;
    }

    /**
     * Eliminar tarjeta
     */
    public function eliminarTarjeta($uid)
    {
        $this->verificarCSRF();
        try {
            // Verificar si la tarjeta tiene registros de asistencia
            $tiene_registros = $this->db->fetch(
                "SELECT COUNT(*) as total FROM asistencias WHERE tarjeta_uid = ?",
                [$uid]
            );

            if ($tiene_registros['total'] > 0) {
                $_SESSION['error'] = 'No se puede eliminar la tarjeta porque tiene registros de asistencia';
                header('Location: /ControlDeAsistencia/admin/tarjetas');
                exit;
            }

            $resultado = $this->db->delete('tarjetas_rfid', ['uid_tarjeta' => $uid]);

            if ($resultado) {
                $_SESSION['success'] = 'Tarjeta eliminada exitosamente';
            } else {
                $_SESSION['error'] = 'Error al eliminar la tarjeta';
            }
        } catch (Exception $e) {
            error_log("Error eliminando tarjeta: " . $e->getMessage());
            $_SESSION['error'] = 'Error interno al eliminar la tarjeta';
        }

        header('Location: /ControlDeAsistencia/admin/tarjetas');
        exit;
    }

    /**
     * Desactivar dispositivo
     */
    public function desactivarDispositivo($id)
    {
        $this->verificarCSRF();
        try {
            $resultado = $this->db->update(
                'dispositivos',
                ['estado' => 'inactivo'],
                ['id' => $id]
            );

            if ($resultado) {
                $_SESSION['success'] = 'Dispositivo desactivado exitosamente';
            } else {
                $_SESSION['error'] = 'Error al desactivar el dispositivo';
            }
        } catch (Exception $e) {
            error_log("Error desactivando dispositivo: " . $e->getMessage());
            $_SESSION['error'] = 'Error interno al desactivar el dispositivo';
        }

        header('Location: /ControlDeAsistencia/admin/dispositivos');
        exit;
    }

    /**
     * Activar dispositivo
     */
    public function activarDispositivo($id)
    {
        $this->verificarCSRF();
        try {
            $resultado = $this->db->update(
                'dispositivos',
                ['estado' => 'activo'],
                ['id' => $id]
            );

            if ($resultado) {
                $_SESSION['success'] = 'Dispositivo activado exitosamente';
            } else {
                $_SESSION['error'] = 'Error al activar el dispositivo';
            }
        } catch (Exception $e) {
            error_log("Error activando dispositivo: " . $e->getMessage());
            $_SESSION['error'] = 'Error interno al activar el dispositivo';
        }

        header('Location: /ControlDeAsistencia/admin/dispositivos');
        exit;
    }

    /**
     * Eliminar dispositivo
     */
    public function eliminarDispositivo($id)
    {
        $this->verificarCSRF();
        try {
            // Verificar si el dispositivo tiene registros de asistencia
            $tiene_registros = $this->db->fetch(
                "SELECT COUNT(*) as total FROM asistencias WHERE dispositivo_id = ?",
                [$id]
            );

            if ($tiene_registros['total'] > 0) {
                $_SESSION['error'] = 'No se puede eliminar el dispositivo porque tiene registros de asistencia';
                header('Location: /ControlDeAsistencia/admin/dispositivos');
                exit;
            }

            $resultado = $this->db->delete('dispositivos', ['id' => $id]);

            if ($resultado) {
                $_SESSION['success'] = 'Dispositivo eliminado exitosamente';
            } else {
                $_SESSION['error'] = 'Error al eliminar el dispositivo';
            }
        } catch (Exception $e) {
            error_log("Error eliminando dispositivo: " . $e->getMessage());
            $_SESSION['error'] = 'Error interno al eliminar el dispositivo';
        }

        header('Location: /ControlDeAsistencia/admin/dispositivos');
        exit;
    }

    /**
     * Obtener detalles de un dispositivo
     */
    public function detallesDispositivo($id)
    {
        try {
            $dispositivo = $this->db->fetch("
                SELECT d.*, 
                       COUNT(a.id) as total_registros,
                       MAX(a.fecha_hora) as ultimo_registro,
                       MIN(a.fecha_hora) as primer_registro
                FROM dispositivos d
                LEFT JOIN asistencias a ON d.id = a.dispositivo_id
                WHERE d.id = ?
                GROUP BY d.id
            ", [$id]);

            if (!$dispositivo) {
                return $this->respuestaJSON(['error' => 'Dispositivo no encontrado'], 404);
            }

            // Escapar todos los valores antes de interpolarlos en HTML. El
            // nombre, ubicación y otros campos del dispositivo los define un
            // administrador vía el formulario de "Registrar Dispositivo" (ver
            // crearDispositivo()) y se guardan tal cual en la base de datos,
            // así que sin escape esto era un vector de XSS almacenado: un
            // nombre/ubicación con <script> se ejecutaría cada vez que
            // cualquier administrador abriera el modal de detalles.
            $safe = array_map(function ($valor) {
                return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
            }, $dispositivo);

            // Generar HTML para mostrar en el modal
            $html = "
                <div class='row'>
                    <div class='col-md-6'>
                        <h6>Información General</h6>
                        <table class='table table-sm'>
                            <tr><td><strong>ID:</strong></td><td>{$safe['id']}</td></tr>
                            <tr><td><strong>Nombre:</strong></td><td>{$safe['nombre']}</td></tr>
                            <tr><td><strong>Ubicación:</strong></td><td>{$safe['ubicacion']}</td></tr>
                            <tr><td><strong>Estado:</strong></td><td>{$safe['estado']}</td></tr>
                            <tr><td><strong>IP:</strong></td><td>{$safe['ip_address']}</td></tr>
                        </table>
                    </div>
                    <div class='col-md-6'>
                        <h6>Estadísticas</h6>
                        <table class='table table-sm'>
                            <tr><td><strong>Total Registros:</strong></td><td>{$safe['total_registros']}</td></tr>
                            <tr><td><strong>Último Ping:</strong></td><td>{$safe['ultimo_ping']}</td></tr>
                            <tr><td><strong>Último Registro:</strong></td><td>{$safe['ultimo_registro']}</td></tr>
                            <tr><td><strong>Primer Registro:</strong></td><td>{$safe['primer_registro']}</td></tr>
                        </table>
                    </div>
                </div>
                <div class='mt-3'>
                    <h6>Token de Autenticación</h6>
                    <div class='alert alert-warning'>
                        <code>{$safe['token_dispositivo']}</code>
                        <button class='btn btn-sm btn-outline-primary float-end' onclick='copiarToken()'>
                            <i class='fas fa-copy'></i> Copiar
                        </button>
                    </div>
                </div>
            ";

            return $this->respuestaJSON(['html' => $html]);
        } catch (Exception $e) {
            error_log("Error obteniendo detalles dispositivo: " . $e->getMessage());
            return $this->respuestaJSON(['error' => 'Error interno'], 500);
        }
    }

    /**
     * Ping a dispositivo específico
     */
    public function pingDispositivo($id)
    {
        try {
            $dispositivo = $this->db->fetch("SELECT * FROM dispositivos WHERE id = ?", [$id]);

            if (!$dispositivo) {
                return $this->respuestaJSON(['error' => 'Dispositivo no encontrado'], 404);
            }

            // Simular ping (en un caso real, aquí se haría un request HTTP al ESP32)
            $ping_exitoso = true; // Placeholder - implementar lógica real de ping

            if ($ping_exitoso) {
                // Actualizar última conexión
                $this->db->update(
                    'dispositivos',
                    ['ultimo_ping' => date('Y-m-d H:i:s')],
                    ['id' => $id]
                );

                return $this->respuestaJSON(['success' => true, 'message' => 'Dispositivo respondió correctamente']);
            } else {
                return $this->respuestaJSON(['success' => false, 'message' => 'Sin respuesta del dispositivo']);
            }
        } catch (Exception $e) {
            error_log("Error en ping dispositivo: " . $e->getMessage());
            return $this->respuestaJSON(['error' => 'Error interno'], 500);
        }
    }

    /**
     * Respuesta JSON helper
     */
    private function respuestaJSON($data, $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}
