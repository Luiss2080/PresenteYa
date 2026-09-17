<?php

/**
 * Controlador de Empleado
 * Sistema de Control de Asistencia
 */

namespace App\Controllers;

use App\Models\Database;
use Exception;

class EmpleadoController
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Verificar autenticación y rol de empleado
        if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_rol'] !== 'empleado') {
            header('Location: /ControlDeAsistencia/?error=' . urlencode('Acceso denegado'));
            exit;
        }
    }

    /**
     * Dashboard de empleado
     */
    public function dashboard()
    {
        $usuario_id = $_SESSION['usuario_id'];

        // Obtener información del empleado
        $empleado = $this->db->fetch("SELECT * FROM usuarios WHERE id = ?", [$usuario_id]);

        // Obtener estadísticas del mes actual
        $estadisticas_mes = $this->obtenerEstadisticasMes($usuario_id);

        // Obtener asistencias recientes
        $asistencias_recientes = $this->obtenerAsistenciasRecientes($usuario_id);

        // Obtener asistencia de hoy
        $asistencia_hoy = $this->obtenerAsistenciaHoy($usuario_id);

        // Obtener información de la tarjeta RFID
        $tarjeta = $this->db->fetch("SELECT uid_tarjeta FROM tarjetas_rfid WHERE usuario_id = ? AND estado = 'activa'", [$usuario_id]);

        // Obtener usuario logueado
        $usuario = $this->obtenerUsuarioLogueado();

        $this->renderViewWithLayout('empleado/dashboard', [
            'usuario' => $usuario,
            'titulo' => 'Panel de Empleado',
            'seccion' => 'Dashboard',
            'empleado' => $empleado,
            'estadisticas_mes' => $estadisticas_mes,
            'asistencias_recientes' => $asistencias_recientes,
            'asistencia_hoy' => $asistencia_hoy,
            'tarjeta' => $tarjeta
        ]);
    }

    /**
     * Ver historial completo de asistencias
     */
    public function historial()
    {
        $usuario_id = $_SESSION['usuario_id'];

        // Filtros
        $filtros = [
            'fecha_inicio' => $_GET['fecha_inicio'] ?? date('Y-m-01'),
            'fecha_fin' => $_GET['fecha_fin'] ?? date('Y-m-d'),
            'tipo' => $_GET['tipo'] ?? ''
        ];

        // Obtener historial de asistencias
        $historial = $this->obtenerHistorialAsistencias($usuario_id, $filtros);

        // Obtener resumen del período
        $resumen_periodo = $this->obtenerResumenPeriodo($usuario_id, $filtros);

        include __DIR__ . '/../Views/empleado/historial.php';
    }

    /**
     * Ver estadísticas personales
     */
    public function estadisticas()
    {
        $usuario_id = $_SESSION['usuario_id'];

        // Estadísticas del año actual
        $estadisticas_anuales = $this->obtenerEstadisticasAnuales($usuario_id);

        // Estadísticas por mes del año actual
        $estadisticas_mensuales = $this->obtenerEstadisticasMensuales($usuario_id);

        // Gráfico de puntualidad
        $datos_puntualidad = $this->obtenerDatosPuntualidad($usuario_id);

        include __DIR__ . '/../Views/empleado/estadisticas.php';
    }

    /**
     * Verificar estado de última marcación
     */
    public function ultimaMarcacion()
    {
        $usuario_id = $_SESSION['usuario_id'];

        $ultima_marcacion = $this->db->fetch("
            SELECT 
                tipo,
                fecha_hora,
                TIME(fecha_hora) as hora,
                es_tardanza,
                CASE 
                    WHEN tipo = 'entrada' THEN 'Última entrada'
                    ELSE 'Última salida'
                END as descripcion
            FROM asistencias 
            WHERE usuario_id = ? 
            ORDER BY fecha_hora DESC 
            LIMIT 1
        ", [$usuario_id]);

        header('Content-Type: application/json');
        echo json_encode($ultima_marcacion);
        exit;
    }

    /**
     * Métodos auxiliares privados
     */
    private function obtenerEstadisticasMes($usuario_id)
    {
        $stats = [];

        // Días trabajados este mes
        $stats['dias_trabajados'] = $this->db->fetch("
            SELECT COUNT(DISTINCT DATE(fecha_hora)) as total
            FROM asistencias 
            WHERE usuario_id = ? 
            AND MONTH(fecha_hora) = MONTH(CURDATE())
            AND YEAR(fecha_hora) = YEAR(CURDATE())
            AND tipo = 'entrada'
        ", [$usuario_id])['total'];

        // Total de marcaciones este mes
        $stats['total_marcaciones'] = $this->db->fetch("
            SELECT COUNT(*) as total
            FROM asistencias 
            WHERE usuario_id = ? 
            AND MONTH(fecha_hora) = MONTH(CURDATE())
            AND YEAR(fecha_hora) = YEAR(CURDATE())
        ", [$usuario_id])['total'];

        // Tardanzas este mes
        $stats['tardanzas_mes'] = $this->db->fetch("
            SELECT COUNT(*) as total
            FROM asistencias 
            WHERE usuario_id = ? 
            AND MONTH(fecha_hora) = MONTH(CURDATE())
            AND YEAR(fecha_hora) = YEAR(CURDATE())
            AND es_tardanza = 1
        ", [$usuario_id])['total'];

        // Marcaciones puntuales
        $stats['puntuales_mes'] = max(0, $stats['dias_trabajados'] - $stats['tardanzas_mes']);

        // Porcentaje de puntualidad
        $stats['porcentaje_puntualidad'] = \App\Utils\AsistenciaCalculator::calcularPorcentajePuntualidad(
            (int) $stats['dias_trabajados'],
            (int) $stats['tardanzas_mes']
        );

        return $stats;
    }

    private function obtenerAsistenciasRecientes($usuario_id)
    {
        return $this->db->fetchAll("
            SELECT 
                a.*,
                DATE(a.fecha_hora) as fecha,
                TIME(a.fecha_hora) as hora,
                d.nombre as dispositivo,
                CASE 
                    WHEN a.tipo = 'entrada' AND a.es_tardanza = 1 THEN 'Entrada con tardanza'
                    WHEN a.tipo = 'entrada' THEN 'Entrada puntual'
                    ELSE 'Salida'
                END as descripcion
            FROM asistencias a
            LEFT JOIN dispositivos d ON a.dispositivo_id = d.id
            WHERE a.usuario_id = ?
            ORDER BY a.fecha_hora DESC
            LIMIT 10
        ", [$usuario_id]);
    }

    private function obtenerHistorialAsistencias($usuario_id, $filtros)
    {
        $sql = "
            SELECT 
                a.*,
                DATE(a.fecha_hora) as fecha,
                TIME(a.fecha_hora) as hora,
                d.nombre as dispositivo,
                DAYNAME(a.fecha_hora) as dia_semana
            FROM asistencias a
            LEFT JOIN dispositivos d ON a.dispositivo_id = d.id
            WHERE a.usuario_id = ?
            AND DATE(a.fecha_hora) BETWEEN ? AND ?
        ";

        $params = [$usuario_id, $filtros['fecha_inicio'], $filtros['fecha_fin']];

        if (!empty($filtros['tipo'])) {
            $sql .= " AND a.tipo = ?";
            $params[] = $filtros['tipo'];
        }

        $sql .= " ORDER BY a.fecha_hora DESC";

        return $this->db->fetchAll($sql, $params);
    }

    private function obtenerResumenPeriodo($usuario_id, $filtros)
    {
        $resumen = [];

        // Total de días en el período
        $fecha_inicio = new \DateTime($filtros['fecha_inicio']);
        $fecha_fin = new \DateTime($filtros['fecha_fin']);
        $resumen['dias_periodo'] = $fecha_inicio->diff($fecha_fin)->days + 1;

        // Días trabajados en el período
        $resumen['dias_trabajados'] = $this->db->fetch("
            SELECT COUNT(DISTINCT DATE(fecha_hora)) as total
            FROM asistencias 
            WHERE usuario_id = ? 
            AND DATE(fecha_hora) BETWEEN ? AND ?
            AND tipo = 'entrada'
        ", [$usuario_id, $filtros['fecha_inicio'], $filtros['fecha_fin']])['total'];

        // Tardanzas en el período
        $resumen['tardanzas'] = $this->db->fetch("
            SELECT COUNT(*) as total
            FROM asistencias 
            WHERE usuario_id = ? 
            AND DATE(fecha_hora) BETWEEN ? AND ?
            AND es_tardanza = 1
        ", [$usuario_id, $filtros['fecha_inicio'], $filtros['fecha_fin']])['total'];

        // Porcentaje de asistencia
        $resumen['porcentaje_asistencia'] = $resumen['dias_periodo'] > 0 ?
            round(($resumen['dias_trabajados'] / $resumen['dias_periodo']) * 100, 2) : 0;

        return $resumen;
    }

    private function obtenerEstadisticasAnuales($usuario_id)
    {
        $año_actual = date('Y');

        return $this->db->fetch("
            SELECT 
                COUNT(DISTINCT DATE(fecha_hora)) as dias_trabajados,
                COUNT(*) as total_marcaciones,
                SUM(es_tardanza) as total_tardanzas,
                COUNT(DISTINCT CASE WHEN tipo = 'entrada' THEN DATE(fecha_hora) END) as total_entradas,
                COUNT(DISTINCT CASE WHEN tipo = 'salida' THEN DATE(fecha_hora) END) as total_salidas
            FROM asistencias 
            WHERE usuario_id = ? 
            AND YEAR(fecha_hora) = ?
        ", [$usuario_id, $año_actual]);
    }

    private function obtenerEstadisticasMensuales($usuario_id)
    {
        $año_actual = date('Y');

        return $this->db->fetchAll("
            SELECT 
                MONTH(fecha_hora) as mes,
                MONTHNAME(fecha_hora) as nombre_mes,
                COUNT(DISTINCT DATE(fecha_hora)) as dias_trabajados,
                SUM(es_tardanza) as tardanzas
            FROM asistencias 
            WHERE usuario_id = ? 
            AND YEAR(fecha_hora) = ?
            AND tipo = 'entrada'
            GROUP BY MONTH(fecha_hora)
            ORDER BY MONTH(fecha_hora)
        ", [$usuario_id, $año_actual]);
    }

    private function obtenerDatosPuntualidad($usuario_id)
    {
        // Últimos 30 días
        return $this->db->fetchAll("
            SELECT 
                DATE(fecha_hora) as fecha,
                CASE WHEN SUM(es_tardanza) > 0 THEN 0 ELSE 1 END as puntual
            FROM asistencias 
            WHERE usuario_id = ? 
            AND fecha_hora >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            AND tipo = 'entrada'
            GROUP BY DATE(fecha_hora)
            ORDER BY DATE(fecha_hora) DESC
        ", [$usuario_id]);
    }

    /**
     * Obtiene la asistencia del día actual para el empleado
     */
    private function obtenerAsistenciaHoy($usuario_id)
    {
        try {
            $sql = "SELECT a.*, d.nombre as dispositivo_nombre,
                           CASE 
                               WHEN a.tipo = 'entrada' AND a.es_tardanza = 0 THEN 'puntual'
                               WHEN a.tipo = 'entrada' AND a.es_tardanza = 1 THEN 'tardanza'
                               WHEN a.tipo = 'salida' THEN 'salida'
                               ELSE 'normal'
                           END as estado,
                           TIME(a.fecha_hora) as hora_entrada
                    FROM asistencias a
                    LEFT JOIN dispositivos d ON a.dispositivo_id = d.id
                    WHERE a.usuario_id = ? 
                      AND DATE(a.fecha_hora) = CURDATE()
                    ORDER BY a.fecha_hora DESC";

            return $this->db->fetchAll($sql, [$usuario_id]);
        } catch (Exception $e) {
            error_log("Error obteniendo asistencia de hoy: " . $e->getMessage());
            return [];
        }
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
        } catch (Exception $e) {
            error_log("Error obteniendo usuario logueado: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Renderiza una vista usando el layout principal
     */
    private function renderViewWithLayout($viewPath, $data = [])
    {
        // Extraer variables para que estén disponibles en las vistas
        extract($data);

        // Capturar el contenido de la vista
        ob_start();
        include __DIR__ . '/../Views/' . $viewPath . '.php';
        $contenido = ob_get_clean();

        // Incluir el layout principal
        include __DIR__ . '/../Views/layouts/main.php';
    }
}
