<?php

/**
 * Controlador de RRHH
 * Sistema de Control de Asistencia
 */

namespace App\Controllers;

use App\Models\Database;
use App\Utils\Auth;
use Exception;

class RRHHController
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Verificar autenticación y rol de RRHH
        if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_rol'] !== 'rrhh') {
            header('Location: /ControlDeAsistencia/?error=' . urlencode('Acceso denegado'));
            exit;
        }
    }

    /**
     * Dashboard de RRHH
     */
    public function dashboard()
    {
        // Obtener estadísticas del día
        $estadisticas = $this->obtenerEstadisticasHoy();

        // Obtener asistencias del día
        $asistencias_hoy = $this->obtenerAsistenciasHoy();

        // Obtener alertas y tardanzas
        $alertas = $this->obtenerAlertas();

        // Obtener usuario logueado
        $usuario = $this->obtenerUsuarioLogueado();

        $this->renderViewWithLayout('rrhh/dashboard', [
            'usuario' => $usuario,
            'titulo' => 'Panel de RRHH',
            'seccion' => 'Dashboard',
            'estadisticas' => $estadisticas,
            'asistencias_hoy' => $asistencias_hoy,
            'alertas' => $alertas
        ]);
    }

    /**
     * Generar reporte de asistencia
     */
    public function reporte()
    {
        $filtros = [
            'fecha_inicio' => $_GET['fecha_inicio'] ?? date('Y-m-01'),
            'fecha_fin' => $_GET['fecha_fin'] ?? date('Y-m-d'),
            'empleado' => $_GET['empleado'] ?? '',
            'tipo_reporte' => $_GET['tipo_reporte'] ?? 'diario'
        ];

        $datos_reporte = $this->generarReporte($filtros);
        $empleados = $this->db->fetchAll("SELECT id, nombres, apellidos, numero_empleado FROM usuarios WHERE activo = 1 AND rol = 'empleado' ORDER BY apellidos, nombres");

        // Obtener usuario logueado
        $usuario = $this->obtenerUsuarioLogueado();

        $this->renderViewWithLayout('rrhh/reportes', [
            'usuario' => $usuario,
            'titulo' => 'Reportes de Asistencia',
            'seccion' => 'Reportes',
            'datos_reporte' => $datos_reporte,
            'empleados' => $empleados,
            'filtros' => $filtros
        ]);
    }

    /**
     * Ver detalle de empleado específico
     */
    public function verEmpleado($id = null)
    {
        if (!$id) {
            $_SESSION['error'] = 'ID de empleado no válido';
            header('Location: /ControlDeAsistencia/rrhh');
            exit;
        }

        // Obtener información del empleado
        $empleado = $this->db->fetch("SELECT * FROM usuarios WHERE id = ? AND activo = 1", [$id]);
        if (!$empleado) {
            $_SESSION['error'] = 'Empleado no encontrado';
            header('Location: /ControlDeAsistencia/rrhh');
            exit;
        }

        // Obtener asistencias del mes actual
        $asistencias_mes = $this->db->fetchAll("
            SELECT a.*, d.nombre as dispositivo_nombre
            FROM asistencias a
            LEFT JOIN dispositivos d ON a.dispositivo_id = d.id
            WHERE a.usuario_id = ? 
            AND MONTH(a.fecha_hora) = MONTH(CURDATE()) 
            AND YEAR(a.fecha_hora) = YEAR(CURDATE())
            ORDER BY a.fecha_hora DESC
        ", [$id]);

        // Calcular estadísticas del empleado
        $estadisticas_empleado = $this->calcularEstadisticasEmpleado($id);

        include __DIR__ . '/../Views/rrhh/detalle_empleado.php';
    }

    /**
     * Exportar reporte a Excel/PDF
     */
    public function exportarReporte()
    {
        $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!Auth::verificarTokenCSRF($token)) {
            http_response_code(403);
            $_SESSION['error'] = 'Token de seguridad inválido o expirado. Vuelve a intentarlo.';
            header('Location: /ControlDeAsistencia/rrhh/reportes');
            exit;
        }

        $filtros = [
            'fecha_inicio' => $_POST['fecha_inicio'] ?? date('Y-m-01'),
            'fecha_fin' => $_POST['fecha_fin'] ?? date('Y-m-d'),
            'empleado' => $_POST['empleado'] ?? '',
            'formato' => $_POST['formato'] ?? 'excel'
        ];

        $datos_reporte = $this->generarReporte($filtros);

        if ($filtros['formato'] === 'excel') {
            $this->exportarExcel($datos_reporte, $filtros);
        } else {
            $this->exportarPDF($datos_reporte, $filtros);
        }
    }

    /**
     * Métodos auxiliares privados
     */
    private function obtenerEstadisticasHoy()
    {
        $stats = [];

        // Total de empleados activos
        $stats['empleados_total'] = $this->db->fetch("SELECT COUNT(*) as total FROM usuarios WHERE activo = 1 AND rol = 'empleado'")['total'];

        // Empleados presentes hoy (que han marcado entrada)
        $stats['presentes_hoy'] = $this->db->fetch("
            SELECT COUNT(DISTINCT usuario_id) as total 
            FROM asistencias 
            WHERE DATE(fecha_hora) = CURDATE() AND tipo = 'entrada'
        ")['total'];

        // Tardanzas de hoy
        $stats['tardanzas_hoy'] = $this->db->fetch("
            SELECT COUNT(*) as total 
            FROM asistencias 
            WHERE DATE(fecha_hora) = CURDATE() AND es_tardanza = 1
        ")['total'];

        // Ausentes (empleados que no han marcado entrada hoy)
        $stats['ausentes_hoy'] = $stats['empleados_total'] - $stats['presentes_hoy'];

        return $stats;
    }

    private function obtenerAsistenciasHoy()
    {
        return $this->db->fetchAll("
            SELECT 
                a.*,
                CONCAT(u.nombres, ' ', u.apellidos) as empleado,
                u.numero_empleado,
                d.nombre as dispositivo,
                TIME(a.fecha_hora) as hora
            FROM asistencias a
            JOIN usuarios u ON a.usuario_id = u.id
            LEFT JOIN dispositivos d ON a.dispositivo_id = d.id
            WHERE DATE(a.fecha_hora) = CURDATE()
            ORDER BY a.fecha_hora DESC
        ");
    }

    private function obtenerAlertas()
    {
        $alertas = [];

        // Empleados con múltiples tardanzas esta semana
        $tardanzas_semana = $this->db->fetchAll("
            SELECT 
                u.nombres,
                u.apellidos,
                u.numero_empleado,
                COUNT(*) as total_tardanzas
            FROM asistencias a
            JOIN usuarios u ON a.usuario_id = u.id
            WHERE a.es_tardanza = 1 
            AND WEEK(a.fecha_hora) = WEEK(CURDATE())
            AND YEAR(a.fecha_hora) = YEAR(CURDATE())
            GROUP BY u.id
            HAVING total_tardanzas >= 3
            ORDER BY total_tardanzas DESC
        ");

        foreach ($tardanzas_semana as $tardanza) {
            $alertas[] = [
                'tipo' => 'warning',
                'mensaje' => "Empleado {$tardanza['nombres']} {$tardanza['apellidos']} ({$tardanza['numero_empleado']}) tiene {$tardanza['total_tardanzas']} tardanzas esta semana"
            ];
        }

        // Empleados ausentes hoy
        $ausentes_hoy = $this->db->fetchAll("
            SELECT u.nombres, u.apellidos, u.numero_empleado
            FROM usuarios u
            LEFT JOIN asistencias a ON u.id = a.usuario_id AND DATE(a.fecha_hora) = CURDATE()
            WHERE u.activo = 1 AND u.rol = 'empleado' AND a.id IS NULL
            ORDER BY u.apellidos, u.nombres
        ");

        if (count($ausentes_hoy) > 0) {
            $alertas[] = [
                'tipo' => 'info',
                'mensaje' => count($ausentes_hoy) . " empleado(s) ausente(s) hoy"
            ];
        }

        return $alertas;
    }

    private function generarReporte($filtros)
    {
        $sql = "
            SELECT 
                u.numero_empleado,
                CONCAT(u.nombres, ' ', u.apellidos) as empleado,
                u.puesto,
                DATE(a.fecha_hora) as fecha,
                MIN(CASE WHEN a.tipo = 'entrada' THEN TIME(a.fecha_hora) END) as primera_entrada,
                MAX(CASE WHEN a.tipo = 'salida' THEN TIME(a.fecha_hora) END) as ultima_salida,
                COUNT(CASE WHEN a.tipo = 'entrada' THEN 1 END) as total_entradas,
                COUNT(CASE WHEN a.tipo = 'salida' THEN 1 END) as total_salidas,
                SUM(a.es_tardanza) as tardanzas,
                CASE 
                    WHEN COUNT(CASE WHEN a.tipo = 'entrada' THEN 1 END) = 0 THEN 'Ausente'
                    WHEN SUM(a.es_tardanza) > 0 THEN 'Tardanza'
                    ELSE 'Puntual'
                END as estado
            FROM usuarios u
            LEFT JOIN asistencias a ON u.id = a.usuario_id 
                AND DATE(a.fecha_hora) BETWEEN ? AND ?
            WHERE u.activo = 1 AND u.rol = 'empleado'
        ";

        $params = [$filtros['fecha_inicio'], $filtros['fecha_fin']];

        if (!empty($filtros['empleado'])) {
            $sql .= " AND u.id = ?";
            $params[] = $filtros['empleado'];
        }

        $sql .= " GROUP BY u.id, DATE(a.fecha_hora) ORDER BY fecha DESC, u.apellidos, u.nombres";

        return $this->db->fetchAll($sql, $params);
    }

    private function calcularEstadisticasEmpleado($usuario_id)
    {
        $stats = [];

        // Estadísticas del mes actual
        $stats['dias_trabajados'] = $this->db->fetch("
            SELECT COUNT(DISTINCT DATE(fecha_hora)) as total
            FROM asistencias 
            WHERE usuario_id = ? 
            AND MONTH(fecha_hora) = MONTH(CURDATE())
            AND YEAR(fecha_hora) = YEAR(CURDATE())
            AND tipo = 'entrada'
        ", [$usuario_id])['total'];

        $stats['tardanzas_mes'] = $this->db->fetch("
            SELECT COUNT(*) as total
            FROM asistencias 
            WHERE usuario_id = ? 
            AND MONTH(fecha_hora) = MONTH(CURDATE())
            AND YEAR(fecha_hora) = YEAR(CURDATE())
            AND es_tardanza = 1
        ", [$usuario_id])['total'];

        $stats['puntualidad'] = $stats['dias_trabajados'] > 0 ?
            round((($stats['dias_trabajados'] - $stats['tardanzas_mes']) / $stats['dias_trabajados']) * 100, 2) : 100;

        return $stats;
    }

    private function exportarExcel($datos, $filtros)
    {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="reporte_asistencia_' . date('Y-m-d') . '.xls"');

        echo "<table border='1'>";
        echo "<tr>";
        echo "<th>Número Empleado</th>";
        echo "<th>Empleado</th>";
        echo "<th>Puesto</th>";
        echo "<th>Fecha</th>";
        echo "<th>Primera Entrada</th>";
        echo "<th>Última Salida</th>";
        echo "<th>Total Entradas</th>";
        echo "<th>Total Salidas</th>";
        echo "<th>Tardanzas</th>";
        echo "<th>Estado</th>";
        echo "</tr>";

        foreach ($datos as $fila) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($fila['numero_empleado']) . "</td>";
            echo "<td>" . htmlspecialchars($fila['empleado']) . "</td>";
            echo "<td>" . htmlspecialchars($fila['puesto']) . "</td>";
            echo "<td>" . htmlspecialchars($fila['fecha']) . "</td>";
            echo "<td>" . htmlspecialchars($fila['primera_entrada']) . "</td>";
            echo "<td>" . htmlspecialchars($fila['ultima_salida']) . "</td>";
            echo "<td>" . htmlspecialchars($fila['total_entradas']) . "</td>";
            echo "<td>" . htmlspecialchars($fila['total_salidas']) . "</td>";
            echo "<td>" . htmlspecialchars($fila['tardanzas']) . "</td>";
            echo "<td>" . htmlspecialchars($fila['estado']) . "</td>";
            echo "</tr>";
        }

        echo "</table>";
        exit;
    }

    private function exportarPDF($datos, $filtros)
    {
        // Implementación básica de PDF (se puede mejorar con librerías como TCPDF)
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="reporte_asistencia_' . date('Y-m-d') . '.pdf"');

        // Por ahora, generar HTML que se puede convertir a PDF
        echo "<!DOCTYPE html><html><head><title>Reporte de Asistencia</title></head><body>";
        echo "<h1>Reporte de Asistencia</h1>";
        echo "<p>Período: " . $filtros['fecha_inicio'] . " al " . $filtros['fecha_fin'] . "</p>";

        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr style='background-color: #f0f0f0;'>";
        echo "<th>Número Empleado</th><th>Empleado</th><th>Fecha</th><th>Estado</th>";
        echo "</tr>";

        foreach ($datos as $fila) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($fila['numero_empleado']) . "</td>";
            echo "<td>" . htmlspecialchars($fila['empleado']) . "</td>";
            echo "<td>" . htmlspecialchars($fila['fecha']) . "</td>";
            echo "<td>" . htmlspecialchars($fila['estado']) . "</td>";
            echo "</tr>";
        }

        echo "</table>";
        echo "</body></html>";
        exit;
    }

    /**
     * API para estadísticas en tiempo real
     */
    public function estadisticasTiempoReal()
    {
        header('Content-Type: application/json');

        try {
            // Obtener estadísticas actualizadas
            $estadisticas = $this->obtenerEstadisticasHoy();

            // Obtener alertas críticas
            $alertas = $this->obtenerAlertasCriticas();

            echo json_encode([
                'success' => true,
                'estadisticas' => $estadisticas,
                'alertas' => $alertas,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            error_log("Error en estadísticas tiempo real: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'error' => 'Error al obtener estadísticas'
            ]);
        }
        exit;
    }

    /**
     * Obtener alertas críticas del sistema
     */
    private function obtenerAlertasCriticas()
    {
        $alertas = [];

        try {
            // Empleados con tardanzas consecutivas (3 días)
            $tardanzasConsecutivas = $this->db->fetchAll("
                SELECT u.nombres, u.apellidos, COUNT(*) as dias_tardanza
                FROM asistencias a
                JOIN usuarios u ON a.usuario_id = u.id
                WHERE a.es_tardanza = 1 
                AND a.fecha_hora >= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
                GROUP BY u.id
                HAVING dias_tardanza >= 3
                ORDER BY dias_tardanza DESC
                LIMIT 5
            ");

            foreach ($tardanzasConsecutivas as $empleado) {
                $alertas[] = [
                    'tipo' => 'warning',
                    'icono' => 'exclamation-triangle',
                    'titulo' => 'Tardanzas Frecuentes',
                    'mensaje' => "{$empleado['nombres']} {$empleado['apellidos']} tiene {$empleado['dias_tardanza']} tardanzas en 3 días",
                    'critica' => true
                ];
            }

            // Ausencias sin justificar
            $ausenciasSinJustificar = $this->db->fetchAll("
                SELECT u.nombres, u.apellidos
                FROM usuarios u
                WHERE u.activo = 1 AND u.rol = 'empleado'
                AND u.id NOT IN (
                    SELECT DISTINCT usuario_id 
                    FROM asistencias 
                    WHERE DATE(fecha_hora) = CURDATE()
                )
                AND CURTIME() > '10:00:00'
                LIMIT 10
            ");

            if (count($ausenciasSinJustificar) > 0) {
                $alertas[] = [
                    'tipo' => 'danger',
                    'icono' => 'user-times',
                    'titulo' => 'Ausencias Sin Justificar',
                    'mensaje' => count($ausenciasSinJustificar) . " empleados ausentes después de las 10:00 AM",
                    'critica' => true
                ];
            }

            // Dispositivos desconectados
            $dispositivosOffline = $this->db->fetchAll("
                SELECT nombre, ubicacion
                FROM dispositivos 
                WHERE estado = 'activo' 
                AND (ultimo_ping IS NULL OR ultimo_ping < DATE_SUB(NOW(), INTERVAL 15 MINUTE))
            ");

            foreach ($dispositivosOffline as $dispositivo) {
                $alertas[] = [
                    'tipo' => 'info',
                    'icono' => 'wifi',
                    'titulo' => 'Dispositivo Desconectado',
                    'mensaje' => "Lector '{$dispositivo['nombre']}' en {$dispositivo['ubicacion']} sin conexión",
                    'critica' => false
                ];
            }

            // Marcaciones sospechosas (misma tarjeta en diferentes ubicaciones muy rápido)
            $marcacionesSospechosas = $this->db->fetchAll("
                SELECT a1.usuario_id, u.nombres, u.apellidos, 
                       d1.nombre as dispositivo1, d2.nombre as dispositivo2,
                       a1.fecha_hora as marcacion1, a2.fecha_hora as marcacion2
                FROM asistencias a1
                JOIN asistencias a2 ON a1.usuario_id = a2.usuario_id
                JOIN usuarios u ON a1.usuario_id = u.id
                JOIN dispositivos d1 ON a1.dispositivo_id = d1.id
                JOIN dispositivos d2 ON a2.dispositivo_id = d2.id
                WHERE DATE(a1.fecha_hora) = CURDATE()
                AND DATE(a2.fecha_hora) = CURDATE()
                AND a1.dispositivo_id != a2.dispositivo_id
                AND ABS(TIMESTAMPDIFF(MINUTE, a1.fecha_hora, a2.fecha_hora)) < 5
                AND a1.id != a2.id
                LIMIT 3
            ");

            foreach ($marcacionesSospechosas as $sospechosa) {
                $alertas[] = [
                    'tipo' => 'warning',
                    'icono' => 'shield-alt',
                    'titulo' => 'Marcación Sospechosa',
                    'mensaje' => "{$sospechosa['nombres']} {$sospechosa['apellidos']} marcó en 2 dispositivos diferentes en menos de 5 minutos",
                    'critica' => true
                ];
            }
        } catch (Exception $e) {
            error_log("Error obteniendo alertas críticas: " . $e->getMessage());
        }

        return $alertas;
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
        // Todas las vistas de RRHH reciben un token CSRF listo para usar en
        // sus formularios (ver exportarReporte()).
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
}
