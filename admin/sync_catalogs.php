<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

use MaritanoDashboard\Lib\UserRepository;

requireAdmin();

$repo = new UserRepository(appDb(), sigaDb());

try {
    $result = $repo->syncCatalogsFromSiga();
    $message = sprintf('Sincronización OK. Marcas: %d. Sucursales: %d.', $result['brands_synced'], $result['branches_synced']);
    redirect('./users.php?message=' . urlencode($message));
} catch (Throwable $e) {
    redirect('./users.php?error=' . urlencode($e->getMessage()));
}
