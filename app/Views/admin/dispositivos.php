<?php

/**
 * Vista de Gestión de Dispositivos ESP32
 * Sistema de Control de Asistencia
 */
?>

<div class="page-header">
    <h1><i class="fas fa-microchip"></i> Gestión de Dispositivos ESP32</h1>
    <p class="subtitle">Administrar lectores RFID y su conectividad</p>
</div>

<div class="actions-bar">
    <button class="btn btn-success" onclick="mostrarModalNuevoDispositivo()">
        <i class="fas fa-plus"></i> Registrar Nuevo Dispositivo
    </button>
    <button class="btn btn-primary" onclick="verificarConectividad()">
        <i class="fas fa-sync"></i> Verificar Conectividad
    </button>
    <button class="btn btn-info" onclick="mostrarEstadisticas()">
        <i class="fas fa-chart-line"></i> Estadísticas
    </button>
</div>

<!-- Resumen de Estado -->
<div class="stats-grid mb-4">
    <?php
    $total_dispositivos = count($dispositivos ?? []);
    $activos = 0;
    $conectados = 0;
    $total_registros = 0;

    foreach ($dispositivos ?? [] as $dispositivo) {
        if ($dispositivo['estado'] === 'activo') $activos++;
        if ($dispositivo['ultimo_ping'] && strtotime($dispositivo['ultimo_ping']) > (time() - 300)) $conectados++;
        $total_registros += $dispositivo['total_registros'];
    }
    ?>

    <div class="stat-card">
        <div class="stat-number"><?= $total_dispositivos ?></div>
        <div class="stat-label"><i class="fas fa-microchip"></i> Total Dispositivos</div>
    </div>
    <div class="stat-card success">
        <div class="stat-number"><?= $activos ?></div>
        <div class="stat-label"><i class="fas fa-check-circle"></i> Activos</div>
    </div>
    <div class="stat-card info">
        <div class="stat-number"><?= $conectados ?></div>
        <div class="stat-label"><i class="fas fa-wifi"></i> Conectados</div>
    </div>
    <div class="stat-card warning">
        <div class="stat-number"><?= number_format($total_registros) ?></div>
        <div class="stat-label"><i class="fas fa-database"></i> Registros Total</div>
    </div>
</div>

<!-- Lista de Dispositivos -->
<div class="card">
    <div class="card-header">
        <h6><i class="fas fa-microchip"></i> Dispositivos ESP32 Registrados</h6>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Dispositivo</th>
                        <th>Ubicación</th>
                        <th>Estado Conexión</th>
                        <th>IP Address</th>
                        <th>Último Ping</th>
                        <th>Registros</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($dispositivos)): ?>
                        <?php foreach ($dispositivos as $dispositivo): ?>
                            <?php
                            $ultimo_ping = $dispositivo['ultimo_ping'];
                            $esta_conectado = $ultimo_ping && strtotime($ultimo_ping) > (time() - 300); // 5 minutos
                            $estado_conexion = $esta_conectado ? 'Conectado' : 'Desconectado';
                            $badge_conexion = $esta_conectado ? 'badge-success' : 'badge-danger';
                            ?>
                            <tr>
                                <td>
                                    <div class="device-info">
                                        <strong><?= htmlspecialchars($dispositivo['nombre']) ?></strong><br>
                                        <small class="text-muted">ID: <?= $dispositivo['id'] ?></small>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($dispositivo['ubicacion'] ?? 'No especificada') ?></td>
                                <td>
                                    <span class="badge <?= $badge_conexion ?>">
                                        <i class="fas fa-circle"></i> <?= $estado_conexion ?>
                                    </span>
                                    <?php if ($dispositivo['estado'] !== 'activo'): ?>
                                        <br><span class="badge badge-secondary">Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= $dispositivo['ip_address'] ? htmlspecialchars($dispositivo['ip_address']) : '<span class="text-muted">No detectada</span>' ?>
                                </td>
                                <td>
                                    <?= $ultimo_ping ?
                                        '<span title="' . $ultimo_ping . '">' . $this->tiempoTranscurrido($ultimo_ping) . '</span>' :
                                        '<span class="text-muted">Nunca</span>' ?>
                                </td>
                                <td>
                                    <span class="badge badge-info"><?= number_format($dispositivo['total_registros']) ?></span>
                                    <?php if ($dispositivo['ultimo_registro']): ?>
                                        <br><small class="text-muted">Último: <?= date('d/m H:i', strtotime($dispositivo['ultimo_registro'])) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-primary" onclick="verDetalles('<?= $dispositivo['id'] ?>')">
                                            <i class="fas fa-info-circle"></i> Detalles
                                        </button>
                                        <button class="btn btn-warning" onclick="editarDispositivo('<?= $dispositivo['id'] ?>')">
                                            <i class="fas fa-edit"></i> Editar
                                        </button>
                                        <button class="btn btn-info" onclick="pingDispositivo('<?= $dispositivo['id'] ?>')">
                                            <i class="fas fa-satellite-dish"></i> Ping
                                        </button>
                                        <?php if ($dispositivo['estado'] === 'activo'): ?>
                                            <button class="btn btn-secondary" onclick="desactivarDispositivo('<?= $dispositivo['id'] ?>')">
                                                <i class="fas fa-pause"></i> Desactivar
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-success" onclick="activarDispositivo('<?= $dispositivo['id'] ?>')">
                                                <i class="fas fa-play"></i> Activar
                                            </button>
                                        <?php endif; ?>
                                        <button class="btn btn-danger" onclick="eliminarDispositivo('<?= $dispositivo['id'] ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted">
                                <i class="fas fa-microchip fa-3x mb-3"></i><br>
                                No hay dispositivos registrados
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: Nuevo Dispositivo -->
<div class="modal fade" id="modalNuevoDispositivo" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus"></i> Registrar Nuevo Dispositivo ESP32</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="/admin/crear-dispositivo" method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Nombre del Dispositivo *</label>
                                <input type="text" name="nombre" class="form-control" required
                                    placeholder="Ej: Lector Principal">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Ubicación *</label>
                                <input type="text" name="ubicacion" class="form-control" required
                                    placeholder="Ej: Entrada Principal - Piso 1">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">MAC Address (opcional)</label>
                                <input type="text" name="mac_address" class="form-control"
                                    placeholder="Ej: AA:BB:CC:DD:EE:FF">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">IP Address (opcional)</label>
                                <input type="text" name="ip_address" class="form-control"
                                    placeholder="Ej: 192.168.1.100">
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Descripción</label>
                        <textarea name="descripcion" class="form-control" rows="3"
                            placeholder="Descripción adicional del dispositivo y su función"></textarea>
                    </div>

                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <strong>Nota:</strong> Se generará automáticamente un token único para el dispositivo.
                        Este token deberá configurarse en el código del ESP32.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i> Registrar Dispositivo
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Detalles del Dispositivo -->
<div class="modal fade" id="modalDetallesDispositivo" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-info-circle"></i> Detalles del Dispositivo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detallesContent">
                <!-- Content will be loaded dynamically -->
            </div>
        </div>
    </div>
</div>

<script>
    const CSRF_TOKEN = <?= json_encode($csrf_token ?? '') ?>;

    // Envía una acción que modifica estado (activar/desactivar/eliminar)
    // como POST con el token CSRF, en vez de navegar a una URL GET. Las
    // rutas de este panel para esas acciones ya sólo aceptan POST.
    function enviarAccionSegura(url) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = url;

        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = 'csrf_token';
        csrfInput.value = CSRF_TOKEN;
        form.appendChild(csrfInput);

        document.body.appendChild(form);
        form.submit();
    }

    function mostrarModalNuevoDispositivo() {
        new bootstrap.Modal(document.getElementById('modalNuevoDispositivo')).show();
    }

    function verDetalles(dispositivoId) {
        // Cargar detalles del dispositivo vía AJAX
        fetch(`/admin/dispositivos/detalles/${dispositivoId}`)
            .then(response => response.json())
            .then(data => {
                document.getElementById('detallesContent').innerHTML = data.html;
                new bootstrap.Modal(document.getElementById('modalDetallesDispositivo')).show();
            })
            .catch(error => {
                alert('Error al cargar los detalles del dispositivo');
            });
    }

    function editarDispositivo(dispositivoId) {
        window.location.href = `/admin/dispositivos/editar/${dispositivoId}`;
    }

    function pingDispositivo(dispositivoId) {
        const btn = event.target.closest('button');
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verificando...';
        btn.disabled = true;

        fetch(`/admin/dispositivos/ping/${dispositivoId}`, {
                method: 'POST'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    btn.innerHTML = '<i class="fas fa-check"></i> Conectado';
                    btn.className = 'btn btn-success btn-sm';
                    setTimeout(() => {
                        btn.innerHTML = originalText;
                        btn.className = 'btn btn-info btn-sm';
                        btn.disabled = false;
                    }, 3000);
                } else {
                    btn.innerHTML = '<i class="fas fa-times"></i> Sin respuesta';
                    btn.className = 'btn btn-danger btn-sm';
                    setTimeout(() => {
                        btn.innerHTML = originalText;
                        btn.className = 'btn btn-info btn-sm';
                        btn.disabled = false;
                    }, 3000);
                }
            })
            .catch(error => {
                btn.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Error';
                btn.className = 'btn btn-warning btn-sm';
                setTimeout(() => {
                    btn.innerHTML = originalText;
                    btn.className = 'btn btn-info btn-sm';
                    btn.disabled = false;
                }, 3000);
            });
    }

    function desactivarDispositivo(dispositivoId) {
        if (confirm('¿Estás seguro de que quieres desactivar este dispositivo?')) {
            enviarAccionSegura(`/admin/dispositivos/desactivar/${dispositivoId}`);
        }
    }

    function activarDispositivo(dispositivoId) {
        enviarAccionSegura(`/admin/dispositivos/activar/${dispositivoId}`);
    }

    function eliminarDispositivo(dispositivoId) {
        if (confirm('¿Estás seguro de que quieres eliminar este dispositivo? Esta acción no se puede deshacer.')) {
            enviarAccionSegura(`/admin/dispositivos/eliminar/${dispositivoId}`);
        }
    }

    function verificarConectividad() {
        alert('Verificando conectividad de todos los dispositivos...');
        // Implementar verificación masiva
    }

    function mostrarEstadisticas() {
        alert('Mostrando estadísticas de dispositivos...');
        // Implementar vista de estadísticas
    }
</script>

<style>
    .device-info strong {
        color: #2c3e50;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
    }

    .stat-card {
        background: white;
        padding: 1.5rem;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        text-align: center;
    }

    .stat-card.success {
        border-left: 4px solid #28a745;
    }

    .stat-card.info {
        border-left: 4px solid #17a2b8;
    }

    .stat-card.warning {
        border-left: 4px solid #ffc107;
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
    }

    .badge-danger {
        background-color: #dc3545;
    }

    .badge-info {
        background-color: #17a2b8;
    }

    .badge-secondary {
        background-color: #6c757d;
    }

    .actions-bar {
        margin-bottom: 1rem;
    }

    .btn-group-sm .btn {
        font-size: 0.75rem;
        padding: 0.25rem 0.5rem;
    }
</style>

<?php
// Helper function for time elapsed
if (!function_exists('tiempoTranscurrido')) {
    function tiempoTranscurrido($datetime)
    {
        $time = time() - strtotime($datetime);

        if ($time < 60) return 'Hace ' . $time . ' seg';
        if ($time < 3600) return 'Hace ' . floor($time / 60) . ' min';
        if ($time < 86400) return 'Hace ' . floor($time / 3600) . ' h';
        return 'Hace ' . floor($time / 86400) . ' días';
    }
}
?>