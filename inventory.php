<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

requireLogin();

if (!canAccessModule('INVENTARIO', 'can_view')) {
    http_response_code(403);
    echo 'No tienes permiso para ver Inventario.';
    exit;
}

$user = currentUser();
$appName = config('app.name', 'Maritano - Gestión Comercial');
$vehicleTypes = $user['allowed_vehicle_type_rows'] ?? [];
$defaultVehicleType = $user['allowed_vehicle_types'][0] ?? 'VN';
$assetVersion = '20260424-2';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($appName) ?> - Inventario</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="./assets/styles.css?v=<?= e($assetVersion) ?>" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg bg-white border-bottom shadow-sm mb-4">
    <div class="container-fluid px-4">
        <span class="navbar-brand fw-semibold"><?= e($appName) ?></span>
        <div class="collapse navbar-collapse show">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle active" href="#" role="button" data-bs-toggle="dropdown">Gestión Comercial</a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="./index.php">Ventas Diarias</a></li>
                        <li><a class="dropdown-item active" href="./inventory.php">Inventario</a></li>
                        <li><span class="dropdown-item-text text-secondary">Ganancias (pendiente)</span></li>
                        <li><span class="dropdown-item-text text-secondary">Estado de Resultados (pendiente)</span></li>
                    </ul>
                </li>
                <?php if (hasRole('ADMIN')): ?>
                    <li class="nav-item"><a class="nav-link" href="./admin/users.php">Administración</a></li>
                <?php endif; ?>
            </ul>
            <div class="d-flex align-items-center gap-3 small">
                <div>
                    <div class="text-secondary">Usuario</div>
                    <div class="fw-semibold"><?= e($user['full_name']) ?></div>
                </div>
                <a href="./logout.php" class="btn btn-outline-secondary btn-sm">Salir</a>
            </div>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 pb-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Inventario valorizado</h1>
            <div class="text-secondary">Fuente: SQL Server SIGA. Stock disponible sin nota de venta asignada.</div>
            <?php if (!empty($vehicleTypes)): ?>
                <div class="mt-3">
                    <div class="btn-group" role="group" aria-label="Tipo de negocio" id="vehicleTypeTabs">
                        <?php foreach ($vehicleTypes as $vehicleType): ?>
                            <?php $code = (string)$vehicleType['code']; ?>
                            <button type="button" class="btn btn-outline-primary vehicle-type-tab <?= $code === $defaultVehicleType ? 'active' : '' ?>" data-vehicle-type="<?= e($code) ?>">
                                <?= e((string)$vehicleType['name']) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <div class="small text-secondary mt-2">Marcas permitidas: <?= !empty($user['allowed_brands']) ? e(implode(', ', $user['allowed_brands'])) : 'Todas las configuradas' ?></div>
            <div class="small text-secondary mt-1">VN usa sucursal corporativa. VU usa <strong>centro_nombre</strong> como local físico para grilla, filtro y gráfico.</div>
        </div>
        <div class="auto-refresh-box justify-content-lg-end">
            <div>
                <div class="small text-secondary">Tipo activo</div>
                <div class="fw-semibold"><span id="activeVehicleTypeLabel">-</span></div>
            </div>
            <div>
                <label for="refreshInterval" class="small text-secondary d-block">Refresco automático</label>
                <select class="form-select form-select-sm" id="refreshInterval" name="refresh_interval">
                    <option value="60">Cada 1 minuto</option>
                    <option value="120">Cada 2 minutos</option>
                    <option value="0">Desactivado</option>
                </select>
            </div>
            <div>
                <div class="small text-secondary">Última actualización</div>
                <div class="last-updated" id="lastUpdatedAt">-</div>
            </div>
            <div>
                <div class="small text-secondary">Estado</div>
                <div id="autoRefreshStatus" class="status-pill">Esperando datos</div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-4 filters-card">
        <div class="card-body">
            <form id="filtersForm" class="row g-3">
                <input type="hidden" id="vehicle_type" name="vehicle_type" value="<?= e($defaultVehicleType) ?>">
                <div class="col-12 col-md-4">
                    <label class="form-label">Marca</label>
                    <select class="form-select" id="brand" name="brand">
                        <option value="">Todas</option>
                    </select>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label">Local</label>
                    <select class="form-select" id="branch" name="branch">
                        <option value="">Todos</option>
                    </select>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label">Ciudad</label>
                    <select class="form-select" id="city" name="city">
                        <option value="">Todas</option>
                    </select>
                </div>
                <div class="col-12 d-flex flex-wrap gap-2 align-items-center">
                    <button class="btn btn-primary" type="submit">Actualizar</button>
                    <button class="btn btn-outline-secondary" type="button" id="resetFilters">Limpiar</button>
                    <span id="autoRefreshMessage" class="change-badge d-none">Inventario actualizado automáticamente</span>
                </div>
            </form>
        </div>
    </div>

    <div id="errorBox" class="alert alert-danger d-none"></div>

    <div class="row g-3 mb-4" id="kpisRow">
        <div class="col-12 col-md-6 col-xl-3"><div class="card kpi-card shadow-sm"><div class="card-body"><div class="kpi-label">Unidades disponibles</div><div class="kpi-value" id="kpiUnits">-</div><div class="kpi-sub">Stock actual disponible</div></div></div></div>
        <div class="col-12 col-md-6 col-xl-3"><div class="card kpi-card shadow-sm"><div class="card-body"><div class="kpi-label">Valor inventario</div><div class="kpi-value" id="kpiReferenceValue">-</div><div class="kpi-sub">Suma valorizada de inventario</div></div></div></div>
        <div class="col-12 col-md-6 col-xl-2"><div class="card kpi-card shadow-sm"><div class="card-body"><div class="kpi-label">Costo inventario</div><div class="kpi-value" id="kpiCostValue">-</div><div class="kpi-sub">Suma valorizada costo</div></div></div></div>
        <div class="col-12 col-md-6 col-xl-2"><div class="card kpi-card shadow-sm"><div class="card-body"><div class="kpi-label">Locales con stock</div><div class="kpi-value" id="kpiBranches">-</div><div class="kpi-sub">Cobertura actual</div></div></div></div>
        <div class="col-12 col-md-6 col-xl-2"><div class="card kpi-card shadow-sm"><div class="card-body"><div class="kpi-label">Marcas con stock</div><div class="kpi-value" id="kpiBrands">-</div><div class="kpi-sub">Marcas visibles</div></div></div></div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-xl-7"><div class="card shadow-sm h-100"><div class="card-header bg-white">Inventario por marca (unidades)</div><div class="card-body"><canvas id="inventoryByBrandChart" height="130"></canvas></div></div></div>
        <div class="col-12 col-xl-5"><div class="card shadow-sm h-100"><div class="card-header bg-white">Inventario por local</div><div class="card-body"><canvas id="inventoryByBranchChart" height="130"></canvas></div></div></div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <span>Detalle de inventario disponible</span>
            <span class="small text-secondary">máximo 600 filas</span>
        </div>
        <div class="card-body table-responsive">
            <table class="table table-sm align-middle table-report" id="detailTable">
                <thead>
                <tr>
                    <th class="sortable" data-sort-key="vehicle_type" data-sort-type="text">Tipo</th>
                    <th class="sortable" data-sort-key="codigo_interno" data-sort-type="number">Número</th>
                    <th class="sortable" data-sort-key="marca" data-sort-type="text">Marca</th>
                    <th class="sortable" data-sort-key="modelo" data-sort-type="text">Modelo</th>
                    <th class="sortable" data-sort-key="patente" data-sort-type="text">Patente</th>
                    <th class="sortable" data-sort-key="local_nombre" data-sort-type="text">Local</th>
                    <th class="sortable" data-sort-key="ciudad" data-sort-type="text">Ciudad</th>
                    <th class="sortable" data-sort-key="dias_inventario" data-sort-type="number">Días inv.</th>
                    <th class="sortable" data-sort-key="valor_lista" data-sort-type="number">Valor lista</th>
                    <th class="sortable" data-sort-key="valor_oferta" data-sort-type="number">Valor oferta</th>
                    <th class="sortable" data-sort-key="valor_inventario" data-sort-type="number">Valor inventario</th>
                </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<script>
window.APP_DEFAULT_VEHICLE_TYPE = '<?= e($defaultVehicleType) ?>';
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="./assets/inventory.js?v=<?= e($assetVersion) ?>"></script>
</body>
</html>
