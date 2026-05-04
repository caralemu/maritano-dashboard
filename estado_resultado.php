<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

requireLogin();

$user = currentUser();
$permissions = $user['module_permissions'] ?? [];
if (!hasRole('ADMIN') && isset($permissions['ESTADO_RESULTADO']) && !canAccessModule('ESTADO_RESULTADO', 'can_view')) {
    http_response_code(403);
    echo 'No tienes permiso para ver Estado de Resultado.';
    exit;
}

$appName = config('app.name', 'Maritano - Gestión Comercial');
$assetVersion = '20260430-estado-resultado-1';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($appName) ?> - Estado de Resultado</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="./assets/styles.css?v=<?= e($assetVersion) ?>" rel="stylesheet">
    <style>
        .er-table-wrapper { max-height: calc(100vh - 330px); overflow: auto; }
        .er-table { font-size: .84rem; min-width: 1700px; }
        .er-table th, .er-table td { white-space: nowrap; }
        .er-table thead th { position: sticky; top: 0; z-index: 3; background: #fff5f4; }
        .er-table .sticky-label { position: sticky; left: 0; z-index: 2; background: inherit; min-width: 230px; }
        .er-table thead .sticky-label { z-index: 4; background: #fff5f4; }
        .er-row-calculated td { font-weight: 800; background: #fff1ef !important; }
        .er-row-section td { border-top: 2px solid #f1c7c2; }
        .er-row-percent td { font-weight: 700; color: #5b3440; background: #fff8f7 !important; }
        .er-negative { color: #b42318; }
        .er-positive { color: #137333; }
        .er-zero { color: #6b7280; }
    </style>
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
                        <li><a class="dropdown-item" href="./inventory.php">Inventario</a></li>
                        <li><a class="dropdown-item active" href="./estado_resultado.php">Estado de Resultado</a></li>
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
            <h1 class="h3 mb-1">Estado de Resultado</h1>
            <div class="text-secondary">Formato directorio. Fuente contable SIGA: <strong>CO_MOVTOS_DET</strong>.</div>
            <div class="small text-secondary mt-1">Montos expresados en miles.</div>
        </div>
        <div class="auto-refresh-box justify-content-lg-end">
            <div>
                <div class="small text-secondary">Periodo</div>
                <div class="fw-semibold" id="periodLabel">-</div>
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
                <div class="col-12 col-md-3">
                    <label class="form-label">Mes</label>
                    <input type="month" class="form-control" id="month" name="month">
                </div>
                <div class="col-12 col-md-3 d-flex align-items-end">
                    <button class="btn btn-outline-secondary" type="button" id="resetFilters">Mes actual</button>
                </div>
                <div class="col-12 col-md-6 d-flex align-items-end justify-content-md-end">
                    <span class="small text-secondary">Al cambiar el mes, la matriz se actualiza automáticamente.</span>
                </div>
            </form>
        </div>
    </div>

    <div id="errorBox" class="alert alert-danger d-none"></div>

    <div class="row g-3 mb-4" id="kpisRow">
        <div class="col-12 col-md-6 col-xl-2"><div class="card kpi-card shadow-sm"><div class="card-body"><div class="kpi-label">Ingresos</div><div class="kpi-value" id="kpiIngreso">-</div><div class="kpi-sub">Total periodo</div></div></div></div>
        <div class="col-12 col-md-6 col-xl-2"><div class="card kpi-card shadow-sm"><div class="card-body"><div class="kpi-label">MC</div><div class="kpi-value" id="kpiMc">-</div><div class="kpi-sub" id="kpiMcPct">-</div></div></div></div>
        <div class="col-12 col-md-6 col-xl-3"><div class="card kpi-card shadow-sm"><div class="card-body"><div class="kpi-label">Resultado Operacional / EBITDA</div><div class="kpi-value" id="kpiEbitda">-</div><div class="kpi-sub">Antes de no operacional</div></div></div></div>
        <div class="col-12 col-md-6 col-xl-3"><div class="card kpi-card shadow-sm"><div class="card-body"><div class="kpi-label">Resultado después de impuesto</div><div class="kpi-value" id="kpiResultado">-</div><div class="kpi-sub" id="kpiResultadoPct">-</div></div></div></div>
        <div class="col-12 col-md-6 col-xl-2"><div class="card kpi-card shadow-sm"><div class="card-body"><div class="kpi-label">Unidad</div><div class="kpi-value fs-4">Miles</div><div class="kpi-sub">M$</div></div></div></div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <span>Estado de Resultado por unidad de negocio</span>
            <span class="small text-secondary">Formato matriz</span>
        </div>
        <div class="card-body p-0 er-table-wrapper">
            <table class="table table-sm align-middle table-report er-table mb-0" id="estadoResultadoTable">
                <thead></thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<script>
window.APP_DEFAULT_MONTH = '<?= (new DateTimeImmutable('first day of this month'))->format('Y-m') ?>';
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="./assets/estado_resultado.js?v=<?= e($assetVersion) ?>"></script>
</body>
</html>
