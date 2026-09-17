<?php

/**
 * Vista de Reportes de RRHH
 * Sistema de Control de Asistencia
 */
?>

<div class="page-header">
    <h1><i class="fas fa-chart-bar"></i> Reportes de Asistencia</h1>
    <p class="subtitle">Generar y exportar reportes detallados de asistencia</p>
</div>

<!-- Filtros del Reporte -->
<div class="card mb-4">
    <div class="card-header">
        <h6><i class="fas fa-filter"></i> Filtros del Reporte</h6>
    </div>
    <div class="card-body">
        <form method="GET" id="formFiltros">
            <div class="row">
                <div class="col-md-3">
                    <label class="form-label">Fecha Inicio *</label>
                    <input type="date" name="fecha_inicio" class="form-control"
                        value="<?= htmlspecialchars($filtros['fecha_inicio'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Fecha Fin *</label>
                    <input type="date" name="fecha_fin" class="form-control"
                        value="<?= htmlspecialchars($filtros['fecha_fin'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Empleado</label>
                    <select name="empleado" class="form-control">
                        <option value="">Todos los empleados</option>
                        <?php if (!empty($empleados)): ?>
                            <?php foreach ($empleados as $empleado): ?>
                                <option value="<?= $empleado['id'] ?>"
                                    <?= ($filtros['empleado'] == $empleado['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($empleado['numero_empleado'] . ' - ' . $empleado['nombres'] . ' ' . $empleado['apellidos']) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Tipo de Reporte</label>
                    <select name="tipo_reporte" class="form-control">
                        <option value="diario" <?= ($filtros['tipo_reporte'] == 'diario') ? 'selected' : '' ?>>Reporte Diario</option>
                        <option value="resumen" <?= ($filtros['tipo_reporte'] == 'resumen') ? 'selected' : '' ?>>Resumen por Empleado</option>
                        <option value="tardanzas" <?= ($filtros['tipo_reporte'] == 'tardanzas') ? 'selected' : '' ?>>Solo Tardanzas</option>
                        <option value="ausencias" <?= ($filtros['tipo_reporte'] == 'ausencias') ? 'selected' : '' ?>>Solo Ausencias</option>
                    </select>
                </div>
            </div>
            <div class="row mt-3">
                <div class="col-md-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Generar Reporte
                    </button>
                    <button type="button" class="btn btn-success" onclick="exportarReporte('excel')">
                        <i class="fas fa-file-excel"></i> Exportar Excel
                    </button>
                    <button type="button" class="btn btn-danger" onclick="exportarReporte('pdf')">
                        <i class="fas fa-file-pdf"></i> Exportar PDF
                    </button>
                    <button type="button" class="btn btn-info" onclick="limpiarFiltros()">
                        <i class="fas fa-eraser"></i> Limpiar
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Resumen del Período -->
<?php if (!empty($datos_reporte)): ?>
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-number"><?= count($datos_reporte) ?></div>
                <div class="stat-label"><i class="fas fa-list"></i> Total Registros</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card success">
                <div class="stat-number">
                    <?= count(array_filter($datos_reporte, function ($r) {
                        return !$r['es_tardanza'];
                    })) ?>
                </div>
                <div class="stat-label"><i class="fas fa-check"></i> Puntuales</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card warning">
                <div class="stat-number">
                    <?= count(array_filter($datos_reporte, function ($r) {
                        return $r['es_tardanza'];
                    })) ?>
                </div>
                <div class="stat-label"><i class="fas fa-clock"></i> Tardanzas</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card info">
                <div class="stat-number">
                    <?= count(array_unique(array_column($datos_reporte, 'usuario_id'))) ?>
                </div>
                <div class="stat-label"><i class="fas fa-users"></i> Empleados</div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Datos del Reporte -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6><i class="fas fa-table"></i> Datos del Reporte</h6>
        <div>
            <span class="badge badge-info">
                Período: <?= date('d/m/Y', strtotime($filtros['fecha_inicio'])) ?> - <?= date('d/m/Y', strtotime($filtros['fecha_fin'])) ?>
            </span>
        </div>
    </div>
    <div class="card-body">
        <?php if (!empty($datos_reporte)): ?>
            <div class="table-responsive">
                <table class="table table-hover" id="tablaReporte">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Empleado</th>
                            <th>Número</th>
                            <th>Entrada</th>
                            <th>Salida</th>
                            <th>Horas Trabajadas</th>
                            <th>Estado</th>
                            <th>Dispositivo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($datos_reporte as $registro): ?>
                            <tr>
                                <td><?= date('d/m/Y', strtotime($registro['fecha'])) ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($registro['nombres'] . ' ' . $registro['apellidos']) ?></strong>
                                </td>
                                <td><?= htmlspecialchars($registro['numero_empleado']) ?></td>
                                <td>
                                    <?= $registro['hora_entrada'] ? date('H:i', strtotime($registro['hora_entrada'])) : '-' ?>
                                </td>
                                <td>
                                    <?= $registro['hora_salida'] ? date('H:i', strtotime($registro['hora_salida'])) : '-' ?>
                                </td>
                                <td>
                                    <?php if ($registro['hora_entrada'] && $registro['hora_salida']): ?>
                                        <?php
                                        $entrada = new DateTime($registro['hora_entrada']);
                                        $salida = new DateTime($registro['hora_salida']);
                                        $diff = $entrada->diff($salida);
                                        echo $diff->format('%H:%I');
                                        ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $estado = 'Presente';
                                    $badgeClass = 'badge-success';

                                    if ($registro['es_tardanza']) {
                                        $estado = 'Tardanza';
                                        $badgeClass = 'badge-warning';
                                    }

                                    if (!$registro['hora_entrada']) {
                                        $estado = 'Ausente';
                                        $badgeClass = 'badge-danger';
                                    }

                                    if (!$registro['hora_salida'] && $registro['hora_entrada']) {
                                        $estado = 'Sin salida';
                                        $badgeClass = 'badge-info';
                                    }
                                    ?>
                                    <span class="badge <?= $badgeClass ?>"><?= $estado ?></span>
                                </td>
                                <td>
                                    <small class="text-muted">
                                        <?= htmlspecialchars($registro['dispositivo_entrada'] ?? 'N/A') ?>
                                        <?php if ($registro['dispositivo_salida'] && $registro['dispositivo_salida'] !== $registro['dispositivo_entrada']): ?>
                                            / <?= htmlspecialchars($registro['dispositivo_salida']) ?>
                                        <?php endif; ?>
                                    </small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="text-center text-muted py-5">
                <i class="fas fa-inbox fa-3x mb-3"></i><br>
                <h5>No hay datos para mostrar</h5>
                <p>Ajusta los filtros para encontrar registros de asistencia.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal: Configuración de Exportación -->
<div class="modal fade" id="modalExportar" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-download"></i> Exportar Reporte</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="/rrhh/exportar-reporte" method="POST" id="formExportar">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                <div class="modal-body">
                    <input type="hidden" name="fecha_inicio" value="<?= htmlspecialchars($filtros['fecha_inicio'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="fecha_fin" value="<?= htmlspecialchars($filtros['fecha_fin'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="empleado" value="<?= htmlspecialchars($filtros['empleado'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="tipo_reporte" value="<?= htmlspecialchars($filtros['tipo_reporte'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="formato" id="formatoExportar">

                    <div class="mb-3">
                        <label class="form-label">Incluir en el reporte:</label>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="incluir_estadisticas" checked>
                            <label class="form-check-label">Estadísticas resumidas</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="incluir_graficos" checked>
                            <label class="form-check-label">Gráficos de tendencia</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="incluir_detalles" checked>
                            <label class="form-check-label">Detalles por empleado</label>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Nombre del archivo:</label>
                        <input type="text" name="nombre_archivo" class="form-control"
                            value="reporte_asistencia_<?= date('Y-m-d') ?>">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-download"></i> Descargar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function exportarReporte(formato) {
        document.getElementById('formatoExportar').value = formato;
        new bootstrap.Modal(document.getElementById('modalExportar')).show();
    }

    function limpiarFiltros() {
        document.querySelector('input[name="fecha_inicio"]').value = '<?= date('Y-m-01') ?>';
        document.querySelector('input[name="fecha_fin"]').value = '<?= date('Y-m-d') ?>';
        document.querySelector('select[name="empleado"]').value = '';
        document.querySelector('select[name="tipo_reporte"]').value = 'diario';
    }

    // Inicializar DataTable si existe
    document.addEventListener('DOMContentLoaded', function() {
        const tabla = document.getElementById('tablaReporte');
        if (tabla && typeof DataTable !== 'undefined') {
            new DataTable(tabla, {
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/es-ES.json'
                },
                pageLength: 25,
                order: [
                    [0, 'desc']
                ],
                responsive: true
            });
        }
    });

    // Auto-submit form cuando cambian los filtros principales
    document.querySelector('select[name="tipo_reporte"]').addEventListener('change', function() {
        document.getElementById('formFiltros').submit();
    });
</script>

<style>
    .stat-card {
        background: white;
        padding: 1.5rem;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        text-align: center;
        margin-bottom: 1rem;
    }

    .stat-card.success {
        border-left: 4px solid #28a745;
    }

    .stat-card.warning {
        border-left: 4px solid #ffc107;
    }

    .stat-card.info {
        border-left: 4px solid #17a2b8;
    }

    .stat-number {
        font-size: 2rem;
        font-weight: bold;
        color: #2c3e50;
    }

    .stat-label {
        color: #6c757d;
        font-size: 0.9rem;
        margin-top: 0.5rem;
    }

    .badge-success {
        background-color: #28a745;
        color: white;
    }

    .badge-warning {
        background-color: #ffc107;
        color: #212529;
    }

    .badge-danger {
        background-color: #dc3545;
        color: white;
    }

    .badge-info {
        background-color: #17a2b8;
        color: white;
    }

    .table th {
        border-top: none;
        font-weight: 600;
        background-color: #f8f9fa;
    }

    .page-header {
        margin-bottom: 2rem;
    }

    .page-header h1 {
        color: #2c3e50;
        margin-bottom: 0.5rem;
    }

    .subtitle {
        color: #6c757d;
        margin-bottom: 0;
    }
</style>