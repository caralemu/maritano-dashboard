<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use MaritanoDashboard\Lib\EstadoResultadoRepository;

requireApiLogin();

$user = currentUser();
$permissions = $user['module_permissions'] ?? [];
if (!hasRole('ADMIN') && isset($permissions['ESTADO_RESULTADO']) && !canAccessModule('ESTADO_RESULTADO', 'can_view')) {
    jsonResponse(['ok' => false, 'error' => 'No tienes permiso para ver este módulo.'], 403);
}

try {
    $filters = [
        'month' => requestString('month'),
    ];

    $repo = new EstadoResultadoRepository(sigaDb());

    jsonResponse([
        'ok' => true,
        'data' => $repo->getEstadoResultadoData($filters),
    ]);
} catch (Throwable $e) {
    jsonResponse([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}
