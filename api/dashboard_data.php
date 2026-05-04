<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use MaritanoDashboard\Lib\DashboardRepository;

requireApiLogin();

if (!canAccessModule('VENTAS_DIARIAS', 'can_view')) {
    jsonResponse(['ok' => false, 'error' => 'No tienes permiso para ver este módulo.'], 403);
}

try {
    $filters = [
        'month' => requestString('month'),
        'brand' => requestString('brand'),
        'seller' => requestString('seller'),
        'branch' => requestString('branch'),
        'city' => requestString('city'),
        'vehicle_type' => requestString('vehicle_type'),
    ];

    $user = currentUser();
    $allowedBrands = $user['allowed_brands'] ?? [];
    $allowedBranches = $user['allowed_branches'] ?? [];
    $allowedVehicleTypes = $user['allowed_vehicle_types'] ?? [];

    $repo = new DashboardRepository(sigaDb(), $allowedBrands, $allowedBranches, $allowedVehicleTypes);

    jsonResponse([
        'ok' => true,
        'data' => $repo->getDashboardData($filters),
    ]);
} catch (Throwable $e) {
    jsonResponse([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}
