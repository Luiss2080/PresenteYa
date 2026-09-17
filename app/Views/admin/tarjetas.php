<?php

/**
 * Vista de Gestión de Tarjetas RFID
 * Sistema de Control de Asistencia
 */
?>

<div class="page-header">
    <h1><i class="fas fa-id-card"></i> Gestión de Tarjetas RFID</h1>
    <p class="subtitle">Asignar y administrar tarjetas RFID para empleados</p>
</div>

<div class="actions-bar">
    <button class="btn btn-success" onclick="mostrarModalNuevaTarjeta()">
        <i class="fas fa-plus"></i> Registrar Nueva Tarjeta
    </button>
    <button class="btn btn-primary" onclick="actualizarListado()">
        <i class="fas fa-sync"></i> Actualizar
    </button>
</div>

<!-- Filtros -->
<div class="card mb-4">
    <div class="card-header">
        <h6><i class="fas fa-filter"></i> Filtros</h6>
    </div>
    <div class="card-body">
        <form method="GET" class="filter-form">
            <div class="row">
                <div class="col-md-3">
                    <label>Estado</label>
                    <select name="estado" class="form-control">
                        <option value="">Todos</option>
                        <option value="activa" <?= ($_GET['estado'] ?? '') === 'activa' ? 'selected' : '' ?>>Activa</option>
                        <option value="inactiva" <?= ($_GET['estado'] ?? '') === 'inactiva' ? 'selected' : '' ?>>Inactiva</option>
                        <option value="bloqueada" <?= ($_GET['estado'] ?? '') === 'bloqueada' ? 'selected' : '' ?>>Bloqueada</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label>Asignación</label>
                    <select name="asignacion" class="form-control">
                        <option value="">Todas</option>
                        <option value="asignadas" <?= ($_GET['asignacion'] ?? '') === 'asignadas' ? 'selected' : '' ?>>Asignadas</option>
                        <option value="sin_asignar" <?= ($_GET['asignacion'] ?? '') === 'sin_asignar' ? 'selected' : '' ?>>Sin Asignar</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label>Buscar</label>
                    <input type="text" name="buscar" class="form-control"
                        placeholder="UID, empleado..." value="<?= htmlspecialchars($_GET['buscar'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn btn-primary form-control">
                        <i class="fas fa-search"></i> Filtrar
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Lista de Tarjetas -->
<div class="card">
    <div class="card-header">
        <h6><i class="fas fa-id-card"></i> Tarjetas RFID Registradas</h6>
        <span class="badge badge-info"><?= count($tarjetas ?? []) ?> tarjetas</span>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>UID Tarjeta</th>
                        <th>Empleado Asignado</th>
                        <th>Estado</th>
                        <th>Fecha Asignación</th>
                        <th>Última Uso</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($tarjetas)): ?>
                        <?php foreach ($tarjetas as $tarjeta): ?>
                            <tr>
                                <td>
                                    <code class="uid-code"><?= htmlspecialchars($tarjeta['uid_tarjeta']) ?></code>
                                </td>
                                <td>
                                    <?php if ($tarjeta['usuario_id']): ?>
                                        <div class="user-info">
                                            <strong><?= htmlspecialchars($tarjeta['nombres'] . ' ' . $tarjeta['apellidos']) ?></strong><br>
                                            <small class="text-muted"><?= htmlspecialchars($tarjeta['numero_empleado']) ?></small>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted"><i>Sin asignar</i></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $badgeClass = [
                                        'activa' => 'badge-success',
                                        'inactiva' => 'badge-secondary',
                                        'bloqueada' => 'badge-danger'
                                    ][$tarjeta['estado']] ?? 'badge-secondary';
                                    ?>
                                    <span class="badge <?= $badgeClass ?>">
                                        <?= ucfirst($tarjeta['estado']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= $tarjeta['fecha_asignacion'] ? date('d/m/Y H:i', strtotime($tarjeta['fecha_asignacion'])) : '-' ?>
                                </td>
                                <td>
                                    <?= $tarjeta['ultimo_uso'] ? date('d/m/Y H:i', strtotime($tarjeta['ultimo_uso'])) : '-' ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <?php if ($tarjeta['usuario_id']): ?>
                                            <button class="btn btn-warning" onclick="reasignarTarjeta('<?= $tarjeta['uid_tarjeta'] ?>')">
                                                <i class="fas fa-exchange-alt"></i> Reasignar
                                            </button>
                                            <button class="btn btn-secondary" onclick="desasignarTarjeta('<?= $tarjeta['uid_tarjeta'] ?>')">
                                                <i class="fas fa-unlink"></i> Desasignar
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-success" onclick="asignarTarjeta('<?= $tarjeta['uid_tarjeta'] ?>')">
                                                <i class="fas fa-user-plus"></i> Asignar
                                            </button>
                                        <?php endif; ?>

                                        <?php if ($tarjeta['estado'] === 'activa'): ?>
                                            <button class="btn btn-danger" onclick="bloquearTarjeta('<?= $tarjeta['uid_tarjeta'] ?>')">
                                                <i class="fas fa-ban"></i> Bloquear
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-success" onclick="activarTarjeta('<?= $tarjeta['uid_tarjeta'] ?>')">
                                                <i class="fas fa-check"></i> Activar
                                            </button>
                                        <?php endif; ?>

                                        <button class="btn btn-danger" onclick="eliminarTarjeta('<?= $tarjeta['uid_tarjeta'] ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted">
                                <i class="fas fa-id-card fa-3x mb-3"></i><br>
                                No hay tarjetas registradas
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: Nueva Tarjeta -->
<div class="modal fade" id="modalNuevaTarjeta" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus"></i> Registrar Nueva Tarjeta RFID</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="/admin/tarjetas/crear" method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">UID de la Tarjeta *</label>
                        <input type="text" name="uid_tarjeta" class="form-control" required
                            placeholder="Ej: A1B2C3D4" maxlength="50">
                        <div class="form-text">
                            <i class="fas fa-info-circle"></i> Ingresa el UID hexadecimal de la tarjeta RFID
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Asignar a Empleado (opcional)</label>
                        <select name="usuario_id" class="form-control">
                            <option value="">Registrar sin asignar</option>
                            <?php if (!empty($usuarios_disponibles)): ?>
                                <?php foreach ($usuarios_disponibles as $usuario): ?>
                                    <option value="<?= $usuario['id'] ?>">
                                        <?= htmlspecialchars($usuario['numero_empleado'] . ' - ' . $usuario['nombres'] . ' ' . $usuario['apellidos']) ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Descripción</label>
                        <textarea name="descripcion" class="form-control" rows="2"
                            placeholder="Descripción opcional de la tarjeta"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i> Registrar Tarjeta
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Asignar Tarjeta -->
<div class="modal fade" id="modalAsignarTarjeta" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-plus"></i> Asignar Tarjeta</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="/admin/tarjetas/asignar" method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="uid_tarjeta" id="uid_asignar">
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        Tarjeta: <code id="uid_display"></code>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Seleccionar Empleado *</label>
                        <select name="usuario_id" class="form-control" required>
                            <option value="">Selecciona un empleado</option>
                            <?php if (!empty($usuarios_disponibles)): ?>
                                <?php foreach ($usuarios_disponibles as $usuario): ?>
                                    <option value="<?= $usuario['id'] ?>">
                                        <?= htmlspecialchars($usuario['numero_empleado'] . ' - ' . $usuario['nombres'] . ' ' . $usuario['apellidos']) ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check"></i> Asignar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    const CSRF_TOKEN = <?= json_encode($csrf_token ?? '') ?>;

    // Envía una acción que modifica estado (bloquear/activar/desasignar/
    // eliminar) como POST con el token CSRF, en vez de navegar a una URL
    // GET. Las rutas de este panel para esas acciones ya sólo aceptan POST.
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

    function mostrarModalNuevaTarjeta() {
        new bootstrap.Modal(document.getElementById('modalNuevaTarjeta')).show();
    }

    function asignarTarjeta(uid) {
        document.getElementById('uid_asignar').value = uid;
        document.getElementById('uid_display').textContent = uid;
        new bootstrap.Modal(document.getElementById('modalAsignarTarjeta')).show();
    }

    function reasignarTarjeta(uid) {
        if (confirm('¿Estás seguro de que quieres reasignar esta tarjeta?')) {
            asignarTarjeta(uid);
        }
    }

    function desasignarTarjeta(uid) {
        if (confirm('¿Estás seguro de que quieres desasignar esta tarjeta?')) {
            enviarAccionSegura(`/admin/tarjetas/desasignar/${uid}`);
        }
    }

    function bloquearTarjeta(uid) {
        if (confirm('¿Estás seguro de que quieres bloquear esta tarjeta?')) {
            enviarAccionSegura(`/admin/tarjetas/bloquear/${uid}`);
        }
    }

    function activarTarjeta(uid) {
        if (confirm('¿Estás seguro de que quieres activar esta tarjeta?')) {
            enviarAccionSegura(`/admin/tarjetas/activar/${uid}`);
        }
    }

    function eliminarTarjeta(uid) {
        if (confirm('¿Estás seguro de que quieres eliminar esta tarjeta? Esta acción no se puede deshacer.')) {
            enviarAccionSegura(`/admin/tarjetas/eliminar/${uid}`);
        }
    }

    function actualizarListado() {
        window.location.reload();
    }
</script>

<style>
    .uid-code {
        background: #f8f9fa;
        padding: 4px 8px;
        border-radius: 4px;
        font-family: monospace;
        font-weight: bold;
    }

    .user-info strong {
        color: #2c3e50;
    }

    .actions-bar {
        margin-bottom: 1rem;
    }

    .filter-form .row {
        align-items: end;
    }

    .badge {
        font-size: 0.8em;
    }

    .btn-group-sm .btn {
        font-size: 0.75rem;
        padding: 0.25rem 0.5rem;
    }
</style>