const state = {
  charts: {},
  options: null,
  refreshTimer: null,
  refreshSeconds: 60,
  lastFingerprint: null,
  isLoading: false,
  pendingReload: false,
  sessionExpired: false,
};

document.addEventListener('DOMContentLoaded', async () => {
  document.getElementById('month').value = window.APP_DEFAULT_MONTH;
  document.getElementById('vehicle_type').value = window.APP_DEFAULT_VEHICLE_TYPE;
  document.getElementById('refreshInterval').value = '60';
  updateVehicleTypeLabel();
  bindEvents();

  try {
    await loadOptions();
    await loadDashboard({ isAutoRefresh: false, showChangeMessage: false });
    startAutoRefresh();
  } catch (error) {
    handleRequestError(error);
  }
});

function bindEvents() {
  document.getElementById('filtersForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
      await loadDashboard({ isAutoRefresh: false, showChangeMessage: false });
    } catch (error) {
      handleRequestError(error);
    }
  });

  document.getElementById('resetFilters').addEventListener('click', async () => {
    document.getElementById('month').value = window.APP_DEFAULT_MONTH;
    document.getElementById('brand').value = '';
    document.getElementById('seller').value = '';
    document.getElementById('branch').value = '';
    document.getElementById('city').value = '';
    hideAutoRefreshMessage();

    try {
      await loadOptions();
      await loadDashboard({ isAutoRefresh: false, showChangeMessage: false });
    } catch (error) {
      handleRequestError(error);
    }
  });

  document.getElementById('month').addEventListener('change', reloadDashboardFromFilters);
  document.getElementById('brand').addEventListener('change', reloadDashboardFromFilters);
  document.getElementById('seller').addEventListener('change', reloadDashboardFromFilters);

  document.getElementById('branch').addEventListener('change', async () => {
    syncCityFromBranch();
    await reloadDashboardFromFilters();
  });

  document.getElementById('city').addEventListener('change', async () => {
    syncBranchListByCity();
    await reloadDashboardFromFilters();
  });

  document.getElementById('refreshInterval').addEventListener('change', () => {
    startAutoRefresh();
    updateAutoRefreshStatus(
      state.refreshSeconds > 0 ? 'Refresco automático activo' : 'Refresco automático desactivado',
      state.refreshSeconds > 0 ? 'success' : 'warning'
    );
  });

  document.querySelectorAll('.vehicle-type-tab').forEach((button) => {
    button.addEventListener('click', async () => {
      setVehicleType(button.dataset.vehicleType || window.APP_DEFAULT_VEHICLE_TYPE);
      document.getElementById('brand').value = '';
      document.getElementById('seller').value = '';
      document.getElementById('branch').value = '';
      document.getElementById('city').value = '';
      hideAutoRefreshMessage();

      try {
        await loadOptions();
        await loadDashboard({ isAutoRefresh: false, showChangeMessage: false });
      } catch (error) {
        handleRequestError(error);
      }
    });
  });

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      stopAutoRefresh();
      updateAutoRefreshStatus('Refresco pausado: pestaña en segundo plano', 'warning');
      return;
    }

    startAutoRefresh();
    loadDashboard({ isAutoRefresh: true, showChangeMessage: false }).catch((error) => {
      handleRequestError(error);
    });
  });
}

function startAutoRefresh() {
  stopAutoRefresh();
  state.refreshSeconds = Number(document.getElementById('refreshInterval').value || 0);

  if (!state.refreshSeconds || state.refreshSeconds <= 0) {
    updateAutoRefreshStatus('Refresco automático desactivado', 'warning');
    return;
  }

  state.refreshTimer = window.setInterval(async () => {
    try {
      await loadDashboard({ isAutoRefresh: true, showChangeMessage: true });
    } catch (error) {
      handleRequestError(error);
    }
  }, state.refreshSeconds * 1000);

  updateAutoRefreshStatus(
    `Refresco automático cada ${state.refreshSeconds === 60 ? '1 minuto' : '2 minutos'}`,
    'success'
  );
}

function stopAutoRefresh() {
  if (state.refreshTimer !== null) {
    window.clearInterval(state.refreshTimer);
    state.refreshTimer = null;
  }
}

function setVehicleType(code) {
  document.getElementById('vehicle_type').value = code;
  document.querySelectorAll('.vehicle-type-tab').forEach((button) => {
    button.classList.toggle('active', button.dataset.vehicleType === code);
  });
  updateVehicleTypeLabel();
}

function updateVehicleTypeLabel() {
  const active = document.getElementById('vehicle_type').value;
  document.getElementById('activeVehicleTypeLabel').textContent = active === 'VU' ? 'Usados' : 'Nuevos';
}

async function fetchJson(url) {
  const response = await fetch(url, {
    cache: 'no-store',
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'X-Requested-With': 'fetch',
    },
  });

  const contentType = (response.headers.get('content-type') || '').toLowerCase();

  if (response.status === 401) {
    const error = new Error('La sesión expiró. Vuelve a iniciar sesión.');
    error.code = 'SESSION_EXPIRED';
    throw error;
  }

  if (!contentType.includes('application/json')) {
    const text = await response.text();
    const error = new Error('El servidor devolvió HTML en vez de JSON. Probablemente la sesión expiró.');
    error.code = 'INVALID_JSON_RESPONSE';
    error.responseText = text;
    throw error;
  }

  const payload = await response.json();

  if (!response.ok) {
    const error = new Error(payload.error || 'Error de servidor.');
    error.code = payload.error || 'REQUEST_FAILED';
    error.payload = payload;
    throw error;
  }

  return payload;
}

async function loadOptions() {
  const params = new URLSearchParams({
    vehicle_type: document.getElementById('vehicle_type').value || window.APP_DEFAULT_VEHICLE_TYPE,
  });

  const payload = await fetchJson(`./api/options.php?${params.toString()}`);

  if (!payload.ok) {
    throw new Error(payload.error || 'No se pudieron cargar las opciones.');
  }

  state.options = payload.data;

  fillSelect(
    'brand',
    payload.data.brands.map((brand) => ({ value: brand, label: brand }))
  );

  fillSelect(
    'seller',
    payload.data.sellers.map((row) => ({
      value: row.vendedor_codigo,
      label: `${row.vendedor} (${row.vendedor_codigo})`,
    }))
  );

  fillSelect(
    'branch',
    payload.data.branches.map((row) => ({
      value: row.sucursal_codigo,
      label: `${row.sucursal} - ${row.ciudad}`,
    }))
  );

  const cities = [...new Set(payload.data.branches.map((row) => row.ciudad).filter(Boolean))].sort();
  fillSelect(
    'city',
    cities.map((city) => ({ value: city, label: city }))
  );
}

function fillSelect(id, items) {
  const select = document.getElementById(id);
  const currentValue = select.value;
  const firstOption = select.querySelector('option').outerHTML;

  select.innerHTML =
    firstOption +
    items
      .map((item) => `<option value="${escapeHtml(item.value)}">${escapeHtml(item.label)}</option>`)
      .join('');

  if ([...select.options].some((option) => option.value === currentValue)) {
    select.value = currentValue;
  } else {
    select.value = '';
  }
}

async function reloadDashboardFromFilters() {
  hideAutoRefreshMessage();
  try {
    await loadDashboard({ isAutoRefresh: false, showChangeMessage: false });
  } catch (error) {
    handleRequestError(error);
  }
}

async function loadDashboard({ isAutoRefresh = false, showChangeMessage = false } = {}) {
  if (state.sessionExpired) {
    return;
  }

  if (state.isLoading) {
    state.pendingReload = true;
    return;
  }

  state.isLoading = true;
  clearError();
  updateAutoRefreshStatus(isAutoRefresh ? 'Actualizando automáticamente…' : 'Actualizando datos…');

  const params = new URLSearchParams({
    month: document.getElementById('month').value || window.APP_DEFAULT_MONTH,
    brand: document.getElementById('brand').value,
    seller: document.getElementById('seller').value,
    branch: document.getElementById('branch').value,
    city: document.getElementById('city').value,
    vehicle_type: document.getElementById('vehicle_type').value || window.APP_DEFAULT_VEHICLE_TYPE,
  });

  try {
    const payload = await fetchJson(`./api/dashboard_data.php?${params.toString()}`);

    if (!payload.ok) {
      throw new Error(payload.error || 'No se pudo cargar el dashboard.');
    }

    const fingerprint = buildFingerprint(payload.data);
    const dataChanged = state.lastFingerprint !== null && state.lastFingerprint !== fingerprint;
    state.lastFingerprint = fingerprint;

    renderDashboard(payload.data);
    updateLastUpdated();

    if (isAutoRefresh && dataChanged && showChangeMessage) {
      showAutoRefreshMessage();
      pulseTableRows();
      updateAutoRefreshStatus('Se detectaron cambios y la pantalla se actualizó sola', 'success');
    } else {
      hideAutoRefreshMessage();
      updateAutoRefreshStatus(
        state.refreshSeconds > 0
          ? `Refresco automático cada ${state.refreshSeconds === 60 ? '1 minuto' : '2 minutos'}`
          : 'Refresco automático desactivado',
        state.refreshSeconds > 0 ? 'success' : 'warning'
      );
    }
  } finally {
    state.isLoading = false;
    if (state.pendingReload && !state.sessionExpired) {
      state.pendingReload = false;
      await loadDashboard({ isAutoRefresh: false, showChangeMessage: false });
    }
  }
}

function handleRequestError(error) {
  console.error(error);

  if (error?.code === 'SESSION_EXPIRED' || error?.code === 'INVALID_JSON_RESPONSE') {
    state.sessionExpired = true;
    stopAutoRefresh();
    showError('La sesión expiró. Serás redirigido al login.');
    updateAutoRefreshStatus('Sesión expirada', 'warning');
    window.setTimeout(() => {
      window.location.href = './login.php?expired=1';
    }, 1200);
    return;
  }

  showError(error?.message || 'No se pudo cargar el dashboard.');
  updateAutoRefreshStatus('Error al actualizar', 'warning');
}

function renderDashboard(data) {
  const meta = data.meta;
  const current = data.kpis.actual;
  const previous = data.kpis.anterior;

  document.getElementById('comparisonMode').textContent =
    meta.comparison_mode === 'month_to_date'
      ? 'Mes actual vs mes anterior al mismo día'
      : 'Mes completo vs mes anterior';

  document.getElementById('activeVehicleTypeLabel').textContent =
    meta.selected_vehicle_type === 'VU' ? 'Usados' : 'Nuevos';

  setKpi('kpiUnits', 'kpiUnitsDelta', current.unidades, previous.unidades, 'unidades');
  setKpi('kpiTotal', 'kpiTotalDelta', current.total_venta, previous.total_venta, 'currency');
  setKpi('kpiCredits', 'kpiCreditsDelta', current.creditos_totales, previous.creditos_totales, 'créditos');

  const currentPenetration = Number(current.penetracion_credito ?? calculatePenetration(current.creditos_totales, current.unidades));
  const previousPenetration = Number(previous.penetracion_credito ?? calculatePenetration(previous.creditos_totales, previous.unidades));
  setPercentagePointKpi('kpiCreditPenetration', 'kpiCreditPenetrationDelta', currentPenetration, previousPenetration);

  setKpi('kpiSellers', 'kpiSellersDelta', current.vendedores_activos, previous.vendedores_activos, 'vendedores');
  setKpi('kpiBranches', 'kpiBranchesDelta', current.sucursales_activas, previous.sucursales_activas, 'sucursales');

  renderDailySalesChart(data.dailySales);
  renderBrandsChart(data.salesByBrand);
  renderSellersChart(data.salesBySeller);
  renderBranchesChart(data.salesByBranch);
  renderTable(data.tableRows);
}

function calculatePenetration(creditos, unidades) {
  const totalUnits = Number(unidades || 0);
  if (totalUnits <= 0) return 0;
  return (Number(creditos || 0) / totalUnits) * 100;
}

function setPercentagePointKpi(valueId, deltaId, current, previous) {
  const valueElement = document.getElementById(valueId);
  const deltaElement = document.getElementById(deltaId);

  if (!valueElement || !deltaElement) return;

  const currentValue = Number(current || 0);
  const previousValue = Number(previous || 0);
  const delta = currentValue - previousValue;

  let className = 'delta-neutral';
  if (delta > 0) className = 'delta-up';
  if (delta < 0) className = 'delta-down';

  valueElement.textContent = formatPercent(currentValue);
  deltaElement.className = `kpi-sub ${className}`;
  deltaElement.textContent = `${delta >= 0 ? '+' : ''}${delta.toFixed(1).replace('.', ',')} pts vs mes anterior (${formatPercent(previousValue)})`;
}

function setKpi(valueId, deltaId, current, previous, kind) {
  const valueElement = document.getElementById(valueId);
  const deltaElement = document.getElementById(deltaId);

  if (!valueElement || !deltaElement) return;

  if (kind === 'currency') {
    valueElement.textContent = formatCurrency(current);
  } else {
    valueElement.textContent = formatNumber(current);
  }

  const delta = Number(current) - Number(previous);
  const deltaPct = previous === 0 ? null : (delta / previous) * 100;

  let className = 'delta-neutral';
  if (delta > 0) className = 'delta-up';
  if (delta < 0) className = 'delta-down';

  let text = '';
  if (deltaPct === null) {
    text = `Mes anterior: ${kind === 'currency' ? formatCurrency(previous) : formatNumber(previous)}`;
  } else {
    const pctLabel = `${deltaPct >= 0 ? '+' : ''}${deltaPct.toFixed(1)}%`;
    const prevLabel = kind === 'currency' ? formatCurrency(previous) : formatNumber(previous);
    text = `${pctLabel} vs mes anterior (${prevLabel})`;
  }

  deltaElement.className = `kpi-sub ${className}`;
  deltaElement.textContent = text;
}

function renderDailySalesChart(rows) {
  const days = [...new Set(rows.map((row) => Number(row.dia)))].sort((a, b) => a - b);
  const currentMap = {};
  const previousMap = {};

  rows.forEach((row) => {
    const map = row.periodo === 'actual' ? currentMap : previousMap;
    map[Number(row.dia)] = Number(row.unidades);
  });

  const labels = days.map(String);
  const currentData = days.map((day) => currentMap[day] || 0);
  const previousData = days.map((day) => previousMap[day] || 0);

  renderChart(
    'dailySalesChart',
    'line',
    {
      labels,
      datasets: [
        {
          label: 'Mes seleccionado',
          data: currentData,
          tension: 0.25,
          borderColor: '#ea392e',
          backgroundColor: 'rgba(234,57,46,0.12)',
          pointBackgroundColor: '#ea392e',
          fill: false,
        },
        {
          label: 'Mes anterior',
          data: previousData,
          tension: 0.25,
          borderColor: '#f39a94',
          backgroundColor: 'rgba(243,154,148,0.12)',
          pointBackgroundColor: '#f39a94',
          fill: false,
        },
      ],
    },
    {
      responsive: true,
      plugins: { legend: { position: 'bottom' } },
      scales: { y: { beginAtZero: true } },
    }
  );
}

function renderBrandsChart(rows) {
  const labels = rows.map((row) => row.marca);
  const currentData = rows.map((row) => Number(row.unidades_actual));
  const previousData = rows.map((row) => Number(row.unidades_anterior));

  renderChart(
    'brandsChart',
    'bar',
    {
      labels,
      datasets: [
        { label: 'Mes seleccionado', data: currentData, backgroundColor: '#ea392e' },
        { label: 'Mes anterior', data: previousData, backgroundColor: '#f6a19b' },
      ],
    },
    {
      responsive: true,
      plugins: { legend: { position: 'bottom' } },
      scales: { x: { ticks: { autoSkip: false } }, y: { beginAtZero: true } },
    }
  );
}

function renderSellersChart(rows) {
  const labels = rows.map((row) => row.vendedor);
  const data = rows.map((row) => Number(row.unidades_actual));

  renderChart(
    'sellersChart',
    'bar',
    {
      labels,
      datasets: [{ label: 'Unidades', data, backgroundColor: '#ea392e' }],
    },
    {
      indexAxis: 'y',
      responsive: true,
      plugins: { legend: { display: false } },
      scales: { x: { beginAtZero: true } },
    }
  );
}

function renderBranchesChart(rows) {
  const labels = rows.map((row) => `${row.sucursal} / ${row.ciudad}`);
  const data = rows.map((row) => Number(row.unidades_actual));

  renderChart(
    'branchesChart',
    'bar',
    {
      labels,
      datasets: [{ label: 'Unidades', data, backgroundColor: '#ea392e' }],
    },
    {
      indexAxis: 'y',
      responsive: true,
      plugins: { legend: { display: false } },
      scales: { x: { beginAtZero: true } },
    }
  );
}

function renderTable(rows) {
  const tbody = document.querySelector('#detailTable tbody');

  if (!rows.length) {
    tbody.innerHTML =
      `<tr><td colspan="12" class="text-center text-secondary py-4">Sin resultados para el filtro actual.</td></tr>`;
    return;
  }

  tbody.innerHTML = rows
    .map((row) => `
      <tr>
        <td>${escapeHtml(row.fecha_factura ?? '')}</td>
        <td>${escapeHtml(String(row.nro_factura ?? ''))}</td>
        <td>${escapeHtml(String(row.num_nota_venta ?? ''))}</td>
        <td><span class="badge ${Number(row.con_credito || 0) === 1 ? 'text-bg-success' : 'text-bg-secondary'}">${Number(row.con_credito || 0) === 1 ? 'Sí' : 'No'}</span></td>
        <td>${escapeHtml(row.marca ?? '')}</td>
        <td>${escapeHtml(row.modelo ?? '')}</td>
        <td>${escapeHtml(row.vendedor ?? '')}</td>
        <td>${escapeHtml(row.sucursal ?? '')}</td>
        <td>${escapeHtml(row.ciudad ?? '')}</td>
        <td>${escapeHtml(row.comuna ?? '')}</td>
        <td class="text-end">${formatCurrency(Number(row.total_venta || 0))}</td>
        <td class="text-end">${formatCurrency(Number(row.total_neto || 0))}</td>
      </tr>
    `)
    .join('');
}

function renderChart(canvasId, type, data, options) {
  if (state.charts[canvasId]) {
    state.charts[canvasId].destroy();
  }

  const ctx = document.getElementById(canvasId);
  state.charts[canvasId] = new Chart(ctx, { type, data, options });
}

function syncCityFromBranch() {
  if (!state.options) return;

  const branchCode = document.getElementById('branch').value;
  if (!branchCode) return;

  const match = state.options.branches.find((branch) => String(branch.sucursal_codigo) === branchCode);
  if (match) {
    document.getElementById('city').value = match.ciudad || '';
  }
}

function syncBranchListByCity() {
  if (!state.options) return;

  const selectedCity = document.getElementById('city').value;
  const branchItems = !selectedCity
    ? state.options.branches
    : state.options.branches.filter((branch) => branch.ciudad === selectedCity);

  fillSelect(
    'branch',
    branchItems.map((row) => ({
      value: row.sucursal_codigo,
      label: `${row.sucursal} - ${row.ciudad}`,
    }))
  );
}

function showError(message) {
  const box = document.getElementById('errorBox');
  box.textContent = message;
  box.classList.remove('d-none');
}

function clearError() {
  const box = document.getElementById('errorBox');
  box.textContent = '';
  box.classList.add('d-none');
}

function showAutoRefreshMessage() {
  const badge = document.getElementById('autoRefreshMessage');
  badge.classList.remove('d-none');
  window.clearTimeout(badge._hideTimer);
  badge._hideTimer = window.setTimeout(() => {
    badge.classList.add('d-none');
  }, 5000);
}

function hideAutoRefreshMessage() {
  const badge = document.getElementById('autoRefreshMessage');
  badge.classList.add('d-none');
  if (badge._hideTimer) {
    window.clearTimeout(badge._hideTimer);
    badge._hideTimer = null;
  }
}

function pulseTableRows() {
  document.querySelectorAll('#detailTable tbody tr').forEach((row) => {
    row.classList.remove('updated-row');
    void row.offsetWidth;
    row.classList.add('updated-row');
  });
}

function updateAutoRefreshStatus(text, variant = '') {
  const box = document.getElementById('autoRefreshStatus');
  box.textContent = text;
  box.className = `status-pill${variant ? ` status-${variant}` : ''}`;
}

function updateLastUpdated() {
  document.getElementById('lastUpdatedAt').textContent = new Intl.DateTimeFormat('es-CL', {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  }).format(new Date());
}

function buildFingerprint(data) {
  return JSON.stringify({
    meta: data.meta,
    kpis: data.kpis,
    dailySales: data.dailySales,
    salesByBrand: data.salesByBrand,
    salesBySeller: data.salesBySeller,
    salesByBranch: data.salesByBranch,
    tableRows: data.tableRows,
  });
}

function formatCurrency(value) {
  return new Intl.NumberFormat('es-CL', {
    style: 'currency',
    currency: 'CLP',
    maximumFractionDigits: 0,
  }).format(Number(value || 0));
}

function formatNumber(value) {
  return new Intl.NumberFormat('es-CL', {
    maximumFractionDigits: 0,
  }).format(Number(value || 0));
}

function formatPercent(value) {
  return `${new Intl.NumberFormat('es-CL', {
    minimumFractionDigits: 1,
    maximumFractionDigits: 1,
  }).format(Number(value || 0))}%`;
}

function escapeHtml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
