<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

use MaritanoDashboard\Lib\UserRepository;

requireAdmin();

$repo = new UserRepository(appDb(), sigaDb());
$roles = $repo->getRoles();
$brands = $repo->getBrands();
$branches = $repo->getBranches();
$vehicleTypes = $repo->getVehicleTypes();
$users = $repo->listUsers();
$editingUser = null;

$assetVersion = '20260420-2';

$id = requestString('id', '');
if ($id !== '') {
    $editingUser = $repo->getUserById((int)$id);
}

$message = requestString('message', '');
$error = requestString('error', '');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Administración de usuarios</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/styles.css?v=<?= e($assetVersion) ?>" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg bg-white border-bottom shadow-sm mb-4">
    <div class="container-fluid px-4">
        <span class="navbar-brand fw-semibold"><?= e(config('app.name', 'Maritano')) ?></span>
        <div class="collapse navbar-collapse show">
            <ul class="navbar-nav me-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Gestión Comercial</a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="../index.php">Ventas Diarias</a></li>
                    </ul>
                </li>
                <li class="nav-item"><a class="nav-link active" href="./users.php">Administración</a></li>
            </ul>
            <a href="../logout.php" class="btn btn-outline-secondary btn-sm">Salir</a>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 pb-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">Administración de usuarios</h1>
            <div class="text-secondary">Base interna MariaDB para seguridad y permisos.</div>
        </div>
        <form method="post" action="./sync_catalogs.php">
            <button class="btn btn-outline-primary" type="submit">Sincronizar marcas y sucursales desde SIGA</button>
        </form>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

    <div class="row g-4">
        <div class="col-12 col-xl-5">
            <div class="card shadow-sm">
                <div class="card-header bg-white fw-semibold"><?= $editingUser ? 'Editar usuario' : 'Nuevo usuario' ?></div>
                <div class="card-body">
                    <form method="post" action="./user_save.php">
                        <input type="hidden" name="id" value="<?= e((string)($editingUser['id'] ?? '')) ?>">
                        <div class="row g-3">
                            <div class="col-md-6"><label class="form-label">Usuario</label><input class="form-control" name="username" required value="<?= e($editingUser['username'] ?? '') ?>"></div>
                            <div class="col-md-6"><label class="form-label">Correo</label><input class="form-control" name="email" value="<?= e($editingUser['email'] ?? '') ?>"></div>
                            <div class="col-md-6"><label class="form-label">Nombre</label><input class="form-control" name="first_name" required value="<?= e($editingUser['first_name'] ?? '') ?>"></div>
                            <div class="col-md-6"><label class="form-label">Apellido</label><input class="form-control" name="last_name" required value="<?= e($editingUser['last_name'] ?? '') ?>"></div>
                            <div class="col-md-6"><label class="form-label">Contraseña <?= $editingUser ? '(solo si cambia)' : '' ?></label><input type="password" class="form-control" name="password"></div>
                            <div class="col-md-6 d-flex align-items-end gap-3">
                                <div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" <?= !isset($editingUser['is_active']) || (int)$editingUser['is_active'] === 1 ? 'checked' : '' ?>><label class="form-check-label">Activo</label></div>
                                <div class="form-check"><input class="form-check-input" type="checkbox" name="force_password_change" value="1" <?= !empty($editingUser['force_password_change']) ? 'checked' : '' ?>><label class="form-check-label">Forzar cambio clave</label></div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Roles</label>
                                <div class="border rounded p-3 small admin-checklist">
                                    <?php foreach ($roles as $role): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="role_ids[]" value="<?= e((string)$role['id']) ?>" <?= in_array((int)$role['id'], $editingUser['role_ids'] ?? [], true) ? 'checked' : '' ?>>
                                            <label class="form-check-label"><?= e($role['name']) ?> (<?= e($role['code']) ?>)</label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Tipos de vehículo permitidos</label>
                                <div class="border rounded p-3 small admin-checklist">
                                    <?php foreach ($vehicleTypes as $vehicleType): ?>
                                        <div class="form-check form-check-inline me-3">
                                            <input class="form-check-input" type="checkbox" name="vehicle_type_ids[]" value="<?= e((string)$vehicleType['id']) ?>" <?= in_array((int)$vehicleType['id'], $editingUser['vehicle_type_ids'] ?? [], true) ? 'checked' : '' ?>>
                                            <label class="form-check-label"><?= e($vehicleType['name']) ?> (<?= e($vehicleType['code']) ?>)</label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Marcas permitidas</label>
                                <div class="border rounded p-3 small admin-checklist">
                                    <?php foreach ($brands as $brand): ?>
                                        <div class="form-check form-check-inline me-3">
                                            <input class="form-check-input" type="checkbox" name="marca_ids[]" value="<?= e((string)$brand['id']) ?>" <?= in_array((int)$brand['id'], $editingUser['marca_ids'] ?? [], true) ? 'checked' : '' ?>>
                                            <label class="form-check-label"><?= e($brand['nombre']) ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Sucursales permitidas</label>
                                <div class="border rounded p-3 small admin-checklist branch-list">
                                    <?php if ($branches === []): ?>
                                        <div class="text-secondary">Aún no hay sucursales internas. Usa el botón de sincronización.</div>
                                    <?php else: ?>
                                        <?php foreach ($branches as $branch): ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="sucursal_ids[]" value="<?= e((string)$branch['id']) ?>" <?= in_array((int)$branch['id'], $editingUser['sucursal_ids'] ?? [], true) ? 'checked' : '' ?>>
                                                <label class="form-check-label"><?= e($branch['nombre']) ?><?php if (!empty($branch['ciudad'])): ?> / <?= e($branch['ciudad']) ?><?php endif; ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex gap-2 mt-4">
                            <button class="btn btn-primary" type="submit">Guardar</button>
                            <a class="btn btn-outline-secondary" href="./users.php">Limpiar</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-7">
            <div class="card shadow-sm">
                <div class="card-header bg-white fw-semibold">Usuarios</div>
                <div class="card-body table-responsive">
                    <table class="table table-sm align-middle table-report">
                        <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Nombre</th>
                            <th>Roles</th>
                            <th>Tipos</th>
                            <th>Marcas</th>
                            <th>Sucursales</th>
                            <th>Estado</th>
                            <th>Último acceso</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?= e($user['username']) ?></td>
                                <td><?= e(trim($user['first_name'] . ' ' . $user['last_name'])) ?></td>
                                <td><?= e($user['roles'] ?? '') ?></td>
                                <td><?= e(implode(', ', $user['vehicle_types'])) ?></td>
                                <td><?= e(implode(', ', $user['brands'])) ?></td>
                                <td><?= e(implode(', ', $user['branches'])) ?></td>
                                <td><span class="badge <?= (int)$user['is_active'] === 1 ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= (int)$user['is_active'] === 1 ? 'Activo' : 'Inactivo' ?></span></td>
                                <td><?= e((string)($user['last_login_at'] ?? '')) ?></td>
                                <td class="text-end">
                                    <div class="d-flex gap-2 justify-content-end">
                                        <a class="btn btn-sm btn-outline-primary" href="./users.php?id=<?= e((string)$user['id']) ?>">Editar</a>
                                        <form method="post" action="./user_toggle.php">
                                            <input type="hidden" name="id" value="<?= e((string)$user['id']) ?>">
                                            <button class="btn btn-sm btn-outline-secondary" type="submit"><?= (int)$user['is_active'] === 1 ? 'Desactivar' : 'Activar' ?></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
