<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

use MaritanoDashboard\Lib\UserRepository;

requireAdmin();

$repo = new UserRepository(appDb(), sigaDb());

try {
    $repo->saveUser([
        'id' => requestString('id', ''),
        'username' => requestString('username', ''),
        'first_name' => requestString('first_name', ''),
        'last_name' => requestString('last_name', ''),
        'email' => requestString('email', ''),
        'password' => requestString('password', ''),
        'is_active' => requestString('is_active', ''),
        'force_password_change' => requestString('force_password_change', ''),
        'role_ids' => requestArray('role_ids'),
        'marca_ids' => requestArray('marca_ids'),
        'sucursal_ids' => requestArray('sucursal_ids'),
        'vehicle_type_ids' => requestArray('vehicle_type_ids'),
    ]);

    auth()->refreshCurrentUser();
    redirect('./users.php?message=' . urlencode('Usuario guardado correctamente.'));
} catch (Throwable $e) {
    $targetId = requestString('id', '');
    $query = $targetId !== '' ? '&id=' . urlencode($targetId) : '';
    redirect('./users.php?error=' . urlencode($e->getMessage()) . $query);
}
