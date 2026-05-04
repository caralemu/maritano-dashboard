const state = {
  charts: {},
  options: null,
  refreshTimer: null,
  refreshSeconds: 60,
  lastFingerprint: null,
  isLoading: false,
  pendingReload: false,
  detailRows: [],
  sort: { key: 'codigo_interno', direction: 'desc', type: 'number' },
};

document.addEventListener('DOMContentLoaded', async () => {
  document.getElementById('vehicle_type').value = window.APP_DEFAULT_VEHICLE_TYPE;
  document.getElementById('refreshInterval').value = '60';
  updateVehicleTypeLabel();
  bindEvents();
  await loadOptions();
  await loadInventory({ isAutoRefresh: false, showChangeMessage: false });
  startAutoRefresh();
});

function bindEvents() {
  document.getElementById('filtersForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    await loadInventory({ isAutoRefresh: false, showChangeMessage: false });
  });

  document.getElementById('resetFilters').addEventListener('click', async () => {
    document.getElementById('brand').value = '';
    document.getElementById('branch').value = '';
    document.getElementById('city').value = '';
    hideAutoRefreshMessage();
    await loadOptions();
    await loadInventory({ isAutoRefresh: false, showChangeMessage: false });
  });

  document.getElementById('branch').addEventListener('change', syncCityFromBranch);
  document.getElementById('city').addEventListener('change', syncBranchListByCity);

  document.getElementById('refreshInterval').addEventListener('change', () => {
    startAutoRefresh();
    updateAutoRefreshStatus(
      state.refreshSeconds > 0 ? 'Refresco automático activo' : 'Refresco automático desactivado',
      state.refreshSeconds > 0 ? 'success' : 'warning'
    );
  });

  document.querySelectorAll('#detailTable thead th.sortable').forEach((header) => {
    header.style.cursor = 'pointer';
    header.addEventListener('click', () => {
      const key = header.dataset.sortKey || '';
      const type = header.dataset.sortType || 'text';
      if (!key) return;

      if (state.sort.key === key) {
        state.sort.direction = state.sort.direction === 'asc' ? 'desc' : 'asc';
      } else {
        state.sort.key = key;
        state.sort.type = type;
        state.sort.direction = type === 'text' ? 'asc' : 'desc';
      }

      renderTable(state.detailRows);
    });
  });

  document.querySelectorAll('.vehicle-type-tab').forEach((button) => {
    button.addEventListener('click', async () => {
      setVehicleType(button.dataset.vehicleType || window.APP_DEFAULT_VEHICLE_TYPE);
      document.getElementById('brand').value = '';
      document.getElementById('branch').value = '';
      document.getElementById('city').value = '';
      hideAutoRefreshMessage();
      await loadOptions();
      await loadInventory({ isAutoRefresh: false, showChangeMessage: false });
    });
  });

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      stopAutoRefresh();
      updateAutoRefreshStatus('Refresco pausado: pestaña en segundo plano', 'warning');
      return;
    }

    startAutoRefresh();
    loadInventory({ isAutoRefresh: true, showChangeMessage: false }).catch(() => {});
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
    await loadInventory({ isAutoRefresh: true, showChangeMessage: true });
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

async function loadOptions() {
  const params = new URLSearchParams({
    vehicle_type: document.getElementById('vehicle_type').value || window.APP_DEFAULT_VEHICLE_TYPE,
  });

  const response = await fetch(`./api/inventory_options.php?${params.toString()}`, { cache: 'no-store' });
  const payload = await response.json();

  if (!payload.ok) {
    showError(payload.error || 'No se pudieron cargar las opciones.');
    return;
  }

  state.options = payload.data;

  fillSelect(
    'brand',
    payload.data.brands.map((brand) => ({ value: brand, label: brand }))
  );

  fillSelect(
    'branch',
    payload.data.branches.map((row) => ({
      value: row.local_codigo,
      label: `${row.local_nombre} - ${row.ciudad}`,
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
  select.innerHTML = firstOption + items.map((item) => `<option value="${escapeHtml(item.value)}">${escapeHtml(item.label)}</option>`).join('');
  if ([...select.options].some((option) => option.value === currentValue)) {
    select.value = currentValue;
  } else {
    select.value = '';
  }
}

async function loadInventory({ isAutoRefresh = false, showChangeMessage = false } = {}) {
  if (state.isLoading) {
    state.pendingReload = true;
    return;
  }

  state.isLoading = true;
  clearError();
  updateAutoRefreshStatus(isAutoRefresh ? 'Actualizando automáticamente…' : 'Actualizando datos…');

  const params = new URLSearchParams({
    brand: document.getElementById('brand').value,
    branch: document.getElementById('branch').value,
    city: document.getElementById('city').value,
    vehicle_type: document.getElementById('vehicle_type').value || window.APP_DEFAULT_VEHICLE_TYPE,
  });

  try {
    const response = await fetch(`./api/inventory_data.php?${params.toString()}`, { cache: 'no-store' });
    const payload = await response.json();

    if (!payload.ok) {
      showError(payload.error || 'No se pudo cargar el inventario.');
      updateAutoRefreshStatus('Error al actualizar', 'warning');
      return;
    }

    const fingerprint = buildFingerprint(payload.data);
    const dataChanged = state.lastFingerprint !== null && state.lastFingerprint !== fingerprint;
    state.lastFingerprint = fingerprint;

    renderInventory(payload.data);
    updateLastUpdated();

    if (isAutoRefresh && dataChanged && showChangeMessage) {
      showAutoRefreshMessage();
      pulseTableRows();
      updateAutoRefreshStatus('Se detectaron cambios y el inventario se actualizó solo', 'success');
    } else {
      hideAutoRefreshMessage();
      updateAutoRefreshStatus(
        state.refreshSeconds > 0
          ? `Refresco automático cada ${state.refreshSeconds === 60 ? '1 minuto' : '2 minutos'}`
          : 'Refresco automático desactivado',
        state.refreshSeconds > 0 ? 'success' : 'warning'
      );
    }
  } catch (error) {
    showError(error?.message || 'No se pudo cargar el inventario.');
    updateAutoRefreshStatus('Error al actualizar', 'warning');
  } finally {
    state.isLoading = false;
    if (state.pendingReload) {
      state.pendingReload = false;
      await loadInventory({ isAutoRefresh: false, showChangeMessage: false });
    }
  }
}

function renderInventory(data) {
  const kpis = data.kpis;

  document.getElementById('activeVehicleTypeLabel').textContent =
    data.meta.selected_vehicle_type === 'VU' ? 'Usados' : 'Nuevos';
  document.getElementById('kpiUnits').textContent = formatNumber(kpis.unidades);
  document.getElementById('kpiReferenceValue').textContent = formatCurrency(kpis.valor_referencia_total);
  document.getElementById('kpiCostValue').textContent = formatCurrency(kpis.valor_costo_total);
  document.getElementById('kpiBranches').textContent = formatNumber(kpis.sucursales_con_stock);
  document.getElementById('kpiBrands').textContent = formatNumber(kpis.marcas_con_stock);

  renderBrandChart(data.inventoryByBrand);
  renderBranchChart(data.inventoryByBranch);
  state.detailRows = Array.isArray(data.detailRows) ? data.detailRows : [];
  renderTable(state.detailRows);
}

function renderBrandChart(rows) {
  const labels = rows.map((row) => row.marca);
  const data = rows.map((row) => Number(row.unidades || 0));

  renderChart(
    'inventoryByBrandChart',
    'bar',
    {
      labels,
      datasets: [{ label: 'Unidades', data, backgroundColor: '#ea392e' }],
    },
    {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: { x: { ticks: { autoSkip: false } }, y: { beginAtZero: true } },
    }
  );
}

function renderBranchChart(rows) {
  const labels = rows.map((row) => `${row.local_nombre} / ${row.ciudad}`);
  const data = rows.map((row) => Number(row.unidades || 0));

  renderChart(
    'inventoryByBranchChart',
    'bar',
    {
      labels,
      datasets: [{ label: 'Unidades', data, backgroundColor: '#f07a71' }],
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
  const sortedRows = sortRows(rows, state.sort);
  updateSortHeaders();

  if (!sortedRows.length) {
    tbody.innerHTML = `<tr><td colspan="11" class="text-center text-secondary py-4">Sin resultados para el filtro actual.</td></tr>`;
    return;
  }

  tbody.innerHTML = sortedRows.map((row) => `
    <tr>
      <td><span class="badge ${row.vehicle_type === 'VU' ? 'text-bg-dark' : 'text-bg-primary'}">${escapeHtml(row.vehicle_type ?? '')}</span></td>
      <td>${escapeHtml(String(row.codigo_interno ?? ''))}</td>
      <td>${escapeHtml(row.marca ?? '')}</td>
      <td>${escapeHtml(row.modelo ?? '')}</td>
      <td>${escapeHtml(row.patente ?? '')}</td>
      <td>${escapeHtml(row.local_nombre ?? '')}</td>
      <td>${escapeHtml(row.ciudad ?? '')}</td>
      <td class="text-end">${formatNumber(Number(row.dias_inventario || 0))}</td>
      <td class="text-end">${formatCurrency(Number(row.valor_lista || 0))}</td>
      <td class="text-end">${formatCurrency(Number(row.valor_oferta || 0))}</td>
      <td class="text-end">${formatCurrency(Number(row.valor_inventario || 0))}</td>
    </tr>
  `).join('');
}

function sortRows(rows, sort) {
  return [...rows].sort((a, b) => compareValues(a, b, sort));
}

function compareValues(a, b, sort) {
  const direction = sort.direction === 'asc' ? 1 : -1;
  const type = sort.type || 'text';
  const key = sort.key;

  const av = a?.[key];
  const bv = b?.[key];

  if (type === 'number') {
    const an = Number(av || 0);
    const bn = Number(bv || 0);
    if (an === bn) return 0;
    return an > bn ? direction : -direction;
  }

  if (type === 'date') {
    const at = Date.parse(av || '');
    const bt = Date.parse(bv || '');
    const an = Number.isNaN(at) ? 0 : at;
    const bn = Number.isNaN(bt) ? 0 : bt;
    if (an === bn) return 0;
    return an > bn ? direction : -direction;
  }

  const as = String(av ?? '').toLocaleUpperCase('es-CL');
  const bs = String(bv ?? '').toLocaleUpperCase('es-CL');
  if (as === bs) return 0;
  return as > bs ? direction : -direction;
}

function updateSortHeaders() {
  document.querySelectorAll('#detailTable thead th.sortable').forEach((header) => {
    header.classList.remove('sorted-asc', 'sorted-desc');
    if (header.dataset.sortKey === state.sort.key) {
      header.classList.add(state.sort.direction === 'asc' ? 'sorted-asc' : 'sorted-desc');
    }
  });
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

  const match = state.options.branches.find((branch) => String(branch.local_codigo) === branchCode);
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
      value: row.local_codigo,
      label: `${row.local_nombre} - ${row.ciudad}`,
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
    inventoryByBrand: data.inventoryByBrand,
    inventoryByBranch: data.inventoryByBranch,
    detailRows: data.detailRows,
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

function escapeHtml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
