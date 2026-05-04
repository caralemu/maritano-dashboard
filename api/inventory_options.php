<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use MaritanoDashboard\Lib\InventoryRepository;

requireLogin();

if (!canAccessModule('INVENTARIO', 'can_view')) {
    jsonResponse(['ok' => false, 'error' => 'No tienes permiso para ver este módulo.'], 403);
}

try {
    $user = currentUser();
    $repo = new InventoryRepository(
        sigaDb(),
        $user['allowed_brands'] ?? [],
        $user['allowed_branches'] ?? [],
        $user['allowed_vehicle_types'] ?? []
    );

    jsonResponse([
        'ok' => true,
        'data' => $repo->getOptions([
            'vehicle_type' => requestString('vehicle_type'),
        ]),
    ]);
} catch (Throwable $e) {
    jsonResponse([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}
