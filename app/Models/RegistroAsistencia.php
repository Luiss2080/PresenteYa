<?php
/**
 * Modelo para Registro de Asistencia
 * Sistema de Control de Asistencia
 */

namespace App\Models;

use App\Models\Database;
use App\Utils\AsistenciaCalculator;

class RegistroAsistencia {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Registrar nueva marcación de asistencia
     */
    public function registrarMarcacion($datos) {
        try {
            $this->db->beginTransaction();
            
            // Obtener información de la tarjeta y usuario
            $tarjetaModel = new TarjetaRFID();
            $tarjeta = $tarjetaModel->obtenerPorUID($datos['uid_tarjeta']);
            
            if (!$tarjeta) {
                throw new \Exception('Tarjeta RFID no encontrada');
            }
            
            if (!$tarjeta['usuario_activo'] || $tarjeta['estado'] !== 'activa') {
                throw new \Exception('Tarjeta no activa o usuario inactivo');
            }
            
            // Verificar si hay marcación reciente (evitar duplicados en 5 minutos)
            $ultimaMarcacion = $this->obtenerUltimaMarcacion($tarjeta['usuario_id']);
            if ($ultimaMarcacion) {
                $ultimaFecha = new \DateTime($ultimaMarcacion['fecha_hora']);
                $fechaActual = new \DateTime($datos['fecha_hora'] ?? 'now');
                $diferencia = $fechaActual->getTimestamp() - $ultimaFecha->getTimestamp();
                
                if ($diferencia < 300) { // 5 minutos = 300 segundos
                    throw new \Exception('Marcación demasiado reciente, espere al menos 5 minutos');
                }
            }
            
            // Determinar tipo de marcación (entrada/salida)
            $tipo = $this->determinarTipoMarcacion($tarjeta['usuario_id'], $datos['fecha_hora'] ?? 'now');
            
            // Validar horarios laborales si es necesario
            $horarioValido = $this->validarHorarioLaboral($tarjeta['usuario_id'], $datos['fecha_hora'] ?? 'now');
            
            // Preparar datos para insertar
            $registro = [
                'usuario_id' => $tarjeta['usuario_id'],
                'tarjeta_id' => $tarjeta['id'],
                'dispositivo_id' => $datos['dispositivo_id'] ?? null,
                'uid_tarjeta' => $datos['uid_tarjeta'],
                'tipo' => $tipo,
                'fecha_hora' => $datos['fecha_hora'] ?? date('Y-m-d H:i:s'),
                'ip_dispositivo' => $datos['ip_dispositivo'] ?? null,
                'ubicacion' => $datos['ubicacion'] ?? null,
                'estado' => 'procesado',
                'valido' => 1,
                'fuera_horario' => !$horarioValido,
                'observaciones' => $datos['observaciones'] ?? null,
                'datos_raw' => json_encode($datos)
            ];
            
            // Insertar registro
            $registroId = $this->db->insert('registros_asistencia', $registro);
            
            // Actualizar estadísticas del día
            $this->actualizarEstadisticasDia($tarjeta['usuario_id'], date('Y-m-d', strtotime($registro['fecha_hora'])));
            
            // Log del sistema
            $this->registrarLog($tarjeta['usuario_id'], 'ASISTENCIA', "Marcación $tipo registrada", $registroId);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'registro_id' => $registroId,
                'tipo' => $tipo,
                'usuario' => $tarjeta['nombres'] . ' ' . $tarjeta['apellidos'],
                'fecha_hora' => $registro['fecha_hora'],
                'fuera_horario' => !$horarioValido,
                'mensaje' => "Marcación de $tipo registrada exitosamente"
            ];
            
        } catch (\Exception $e) {
            $this->db->rollback();
            
            // Registrar marcación fallida
            $this->registrarMarcacionFallida($datos, $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'codigo_error' => $e->getCode()
            ];
        }
    }

    /**
     * Determinar si la próxima marcación debe ser entrada o salida
     */
    private function determinarTipoMarcacion($usuarioId, $fechaHora) {
        $fecha = date('Y-m-d', strtotime($fechaHora));
        
        // Buscar la última marcación válida del día
        $sql = "SELECT tipo FROM registros_asistencia 
                WHERE usuario_id = ? 
                AND DATE(fecha_hora) = ? 
                AND valido = 1 
                AND estado = 'procesado'
                ORDER BY fecha_hora DESC 
                LIMIT 1";
        
        $ultimaMarcacion = $this->db->fetch($sql, [$usuarioId, $fecha]);

        return AsistenciaCalculator::determinarTipoMarcacion(
            $ultimaMarcacion ? $ultimaMarcacion['tipo'] : null
        );
    }

    /**
     * Validar si la marcación está dentro del horario laboral
     */
    private function validarHorarioLaboral($usuarioId, $fechaHora) {
        // Obtener horario del usuario
        $sql = "SELECT h.* FROM horarios_trabajo h
                INNER JOIN usuarios u ON u.horario_id = h.id
                WHERE u.id = ?";
        
        $horario = $this->db->fetch($sql, [$usuarioId]);
        
        if (!$horario) {
            return true; // Sin horario definido, cualquier hora es válida
        }
        
        $hora = date('H:i:s', strtotime($fechaHora));
        $diaSemana = (int) date('N', strtotime($fechaHora)); // 1=lunes, 7=domingo

        // Verificar si es día laboral
        $diasLaborales = json_decode($horario['dias_laborales'], true);
        if (!AsistenciaCalculator::esDiaLaboral($diaSemana, $diasLaborales)) {
            return false; // No es día laboral
        }

        return AsistenciaCalculator::estaEnHorarioLaboral(
            $hora,
            $horario['hora_entrada'],
            $horario['hora_salida'],
            $horario['tolerancia_minutos'] ?? 15
        );
    }

    /**
     * Obtener última marcación de un usuario
     */
    public function obtenerUltimaMarcacion($usuarioId, $fecha = null) {
        $sql = "SELECT * FROM registros_asistencia 
                WHERE usuario_id = ? AND valido = 1";
        $params = [$usuarioId];
        
        if ($fecha) {
            $sql .= " AND DATE(fecha_hora) = ?";
            $params[] = $fecha;
        }
        
        $sql .= " ORDER BY fecha_hora DESC LIMIT 1";
        
        return $this->db->fetch($sql, $params);
    }

    /**
     * Obtener marcaciones de un usuario por fecha
     */
    public function obtenerMarcacionesPorFecha($usuarioId, $fecha) {
        $sql = "SELECT ra.*, d.nombre as dispositivo_nombre, d.ubicacion as dispositivo_ubicacion
                FROM registros_asistencia ra
                LEFT JOIN dispositivos d ON ra.dispositivo_id = d.id
                WHERE ra.usuario_id = ? 
                AND DATE(ra.fecha_hora) = ?
                AND ra.valido = 1
                ORDER BY ra.fecha_hora ASC";
        
        return $this->db->fetchAll($sql, [$usuarioId, $fecha]);
    }

    /**
     * Obtener marcaciones de un usuario por rango de fechas
     */
    public function obtenerMarcacionesPorRango($usuarioId, $fechaInicio, $fechaFin) {
        $sql = "SELECT ra.*, d.nombre as dispositivo_nombre, d.ubicacion as dispositivo_ubicacion,
                       DATE(ra.fecha_hora) as fecha,
                       TIME(ra.fecha_hora) as hora
                FROM registros_asistencia ra
                LEFT JOIN dispositivos d ON ra.dispositivo_id = d.id
                WHERE ra.usuario_id = ? 
                AND DATE(ra.fecha_hora) BETWEEN ? AND ?
                AND ra.valido = 1
                ORDER BY ra.fecha_hora DESC";
        
        return $this->db->fetchAll($sql, [$usuarioId, $fechaInicio, $fechaFin]);
    }

    /**
     * Obtener resumen de asistencia por día
     */
    public function obtenerResumenDiario($usuarioId, $fecha) {
        $marcaciones = $this->obtenerMarcacionesPorFecha($usuarioId, $fecha);
        
        $resumen = [
            'fecha' => $fecha,
            'total_marcaciones' => count($marcaciones),
            'primera_entrada' => null,
            'ultima_salida' => null,
            'horas_trabajadas' => 0,
            'tiempo_pausas' => 0,
            'estado' => 'ausente',
            'marcaciones' => $marcaciones
        ];
        
        if (empty($marcaciones)) {
            return $resumen;
        }
        
        // Encontrar primera entrada y última salida
        foreach ($marcaciones as $marcacion) {
            if ($marcacion['tipo'] === 'entrada' && !$resumen['primera_entrada']) {
                $resumen['primera_entrada'] = $marcacion['fecha_hora'];
            }
            if ($marcacion['tipo'] === 'salida') {
                $resumen['ultima_salida'] = $marcacion['fecha_hora'];
            }
        }
        
        // Calcular horas trabajadas
        if ($resumen['primera_entrada'] && $resumen['ultima_salida']) {
            $resumen['horas_trabajadas'] = AsistenciaCalculator::calcularHorasTrabajadas(
                $resumen['primera_entrada'],
                $resumen['ultima_salida']
            );
            $resumen['estado'] = 'presente';
        } elseif ($resumen['primera_entrada']) {
            $resumen['estado'] = 'en_oficina';
        }
        
        return $resumen;
    }

    /**
     * Obtener estadísticas de asistencia por rango de fechas
     */
    public function obtenerEstadisticasRango($usuarioId, $fechaInicio, $fechaFin) {
        $sql = "SELECT 
                    COUNT(DISTINCT DATE(fecha_hora)) as dias_presentes,
                    COUNT(*) as total_marcaciones,
                    COUNT(CASE WHEN tipo = 'entrada' THEN 1 END) as total_entradas,
                    COUNT(CASE WHEN tipo = 'salida' THEN 1 END) as total_salidas,
                    COUNT(CASE WHEN fuera_horario = 1 THEN 1 END) as marcaciones_fuera_horario,
                    AVG(CASE WHEN tipo = 'entrada' THEN HOUR(fecha_hora) + MINUTE(fecha_hora)/60 END) as promedio_hora_entrada,
                    AVG(CASE WHEN tipo = 'salida' THEN HOUR(fecha_hora) + MINUTE(fecha_hora)/60 END) as promedio_hora_salida
                FROM registros_asistencia 
                WHERE usuario_id = ? 
                AND DATE(fecha_hora) BETWEEN ? AND ?
                AND valido = 1";
        
        return $this->db->fetch($sql, [$usuarioId, $fechaInicio, $fechaFin]);
    }

    /**
     * Obtener reporte de asistencia para RRHH
     */
    public function obtenerReporteRRHH($filtros = []) {
        $where = ["ra.valido = 1"];
        $params = [];

        if (!empty($filtros['fecha_inicio'])) {
            $where[] = "DATE(ra.fecha_hora) >= ?";
            $params[] = $filtros['fecha_inicio'];
        }

        if (!empty($filtros['fecha_fin'])) {
            $where[] = "DATE(ra.fecha_hora) <= ?";
            $params[] = $filtros['fecha_fin'];
        }

        if (!empty($filtros['usuario_id'])) {
            $where[] = "ra.usuario_id = ?";
            $params[] = $filtros['usuario_id'];
        }

        if (!empty($filtros['departamento_id'])) {
            $where[] = "u.departamento_id = ?";
            $params[] = $filtros['departamento_id'];
        }

        if (isset($filtros['fuera_horario']) && $filtros['fuera_horario'] !== '') {
            $where[] = "ra.fuera_horario = ?";
            $params[] = $filtros['fuera_horario'];
        }

        $whereClause = implode(' AND ', $where);

        $sql = "SELECT ra.*, 
                       CONCAT(u.nombres, ' ', u.apellidos) as usuario_nombre,
                       u.numero_empleado,
                       d.nombre as departamento_nombre,
                       dp.nombre as dispositivo_nombre,
                       dp.ubicacion as dispositivo_ubicacion,
                       DATE(ra.fecha_hora) as fecha,
                       TIME(ra.fecha_hora) as hora
                FROM registros_asistencia ra
                INNER JOIN usuarios u ON ra.usuario_id = u.id
                LEFT JOIN departamentos d ON u.departamento_id = d.id
                LEFT JOIN dispositivos dp ON ra.dispositivo_id = dp.id
                WHERE {$whereClause}
                ORDER BY ra.fecha_hora DESC";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Actualizar estadísticas diarias
     */
    private function actualizarEstadisticasDia($usuarioId, $fecha) {
        // Esta función podría actualizar una tabla de estadísticas resumidas
        // Por ahora solo registramos en log
        $this->registrarLog($usuarioId, 'ESTADISTICAS', "Estadísticas actualizadas para $fecha");
    }

    /**
     * Registrar marcación fallida
     */
    private function registrarMarcacionFallida($datos, $error) {
        $marcacionFallida = [
            'uid_tarjeta' => $datos['uid_tarjeta'] ?? 'desconocido',
            'dispositivo_id' => $datos['dispositivo_id'] ?? null,
            'fecha_hora' => $datos['fecha_hora'] ?? date('Y-m-d H:i:s'),
            'error' => $error,
            'datos_raw' => json_encode($datos),
            'ip_dispositivo' => $datos['ip_dispositivo'] ?? null,
            'estado' => 'error'
        ];
        
        try {
            $this->db->insert('marcaciones_fallidas', $marcacionFallida);
        } catch (\Exception $e) {
            // Si no se puede registrar la marcación fallida, al menos loguearlo
            error_log("Error registrando marcación fallida: " . $e->getMessage());
        }
    }

    /**
     * Corregir marcación
     */
    public function corregirMarcacion($registroId, $nuevaFechaHora, $motivo, $usuarioCorrector) {
        try {
            $this->db->beginTransaction();
            
            // Obtener registro original
            $registro = $this->db->fetch("SELECT * FROM registros_asistencia WHERE id = ?", [$registroId]);
            
            if (!$registro) {
                throw new \Exception('Registro no encontrado');
            }
            
            // Guardar datos originales
            $datosOriginales = [
                'fecha_hora_original' => $registro['fecha_hora'],
                'corregido_por' => $usuarioCorrector,
                'motivo_correccion' => $motivo,
                'fecha_correccion' => date('Y-m-d H:i:s')
            ];
            
            // Actualizar registro
            $this->db->update(
                'registros_asistencia',
                [
                    'fecha_hora' => $nuevaFechaHora,
                    'observaciones' => $registro['observaciones'] . " | CORREGIDO: $motivo",
                    'datos_correccion' => json_encode($datosOriginales)
                ],
                'id = ?',
                [$registroId]
            );
            
            // Registrar corrección en log
            $this->registrarLog(
                $registro['usuario_id'], 
                'CORRECCION', 
                "Marcación corregida por usuario $usuarioCorrector. Motivo: $motivo", 
                $registroId
            );
            
            $this->db->commit();
            return true;
            
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Invalidar marcación
     */
    public function invalidarMarcacion($registroId, $motivo, $usuarioAnulador) {
        try {
            $this->db->beginTransaction();
            
            $this->db->update(
                'registros_asistencia',
                [
                    'valido' => 0,
                    'observaciones' => "ANULADO: $motivo",
                    'anulado_por' => $usuarioAnulador,
                    'fecha_anulacion' => date('Y-m-d H:i:s')
                ],
                'id = ?',
                [$registroId]
            );
            
            // Registrar anulación en log
            $registro = $this->db->fetch("SELECT usuario_id FROM registros_asistencia WHERE id = ?", [$registroId]);
            $this->registrarLog(
                $registro['usuario_id'], 
                'ANULACION', 
                "Marcación anulada por usuario $usuarioAnulador. Motivo: $motivo", 
                $registroId
            );
            
            $this->db->commit();
            return true;
            
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Registrar en log del sistema
     */
    private function registrarLog($usuarioId, $accion, $descripcion, $registroId = null) {
        $log = [
            'usuario_id' => $usuarioId,
            'accion' => $accion,
            'tabla_afectada' => 'registros_asistencia',
            'id_registro' => $registroId,
            'descripcion' => $descripcion,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'API'
        ];
        
        $this->db->insert('logs_sistema', $log);
    }

    /**
     * Obtener marcaciones pendientes de procesar
     */
    public function obtenerMarcacionesPendientes() {
        $sql = "SELECT * FROM registros_asistencia 
                WHERE estado = 'pendiente'
                ORDER BY fecha_hora ASC";
        
        return $this->db->fetchAll($sql);
    }

    /**
     * Procesar marcaciones en lote
     */
    public function procesarMarcacionesLote($marcaciones) {
        $resultados = [];
        
        foreach ($marcaciones as $marcacion) {
            $resultado = $this->registrarMarcacion($marcacion);
            $resultados[] = $resultado;
        }
        
        return $resultados;
    }
}
?>