const state = {
  columns: [],
  rows: [],
  isLoading: false,
  pendingReload: false,
  sessionExpired: false,
};

document.addEventListener('DOMContentLoaded', async () => {
  document.getElementById('month').value = window.APP_DEFAULT_MONTH;
  bindEvents();
  await loadEstadoResultado();
});

function bindEvents() {
  document.getElementById('filtersForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    await loadEstadoResultado();
  });

  document.getElementById('month').addEventListener('change', async () => {
    await loadEstadoResultado();
  });

  document.getElementById('resetFilters').addEventListener('click', async () => {
    document.getElementById('month').value = window.APP_DEFAULT_MONTH;
    await loadEstadoResultado();
  });
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

async function loadEstadoResultado() {
  if (state.sessionExpired) return;

  if (state.isLoading) {
    state.pendingReload = true;
    return;
  }

  state.isLoading = true;
  clearError();
  updateStatus('Actualizando datos…');

  const params = new URLSearchParams({
    month: document.getElementById('month').value || window.APP_DEFAULT_MONTH,
  });

  try {
    const payload = await fetchJson(`./api/estado_resultado_data.php?${params.toString()}`);

    if (!payload.ok) {
      throw new Error(payload.error || 'No se pudo cargar el Estado de Resultado.');
    }

    renderEstadoResultado(payload.data);
    updateLastUpdated();
    updateStatus('Datos actualizados', 'success');
  } catch (error) {
    handleRequestError(error);
  } finally {
    state.isLoading = false;
    if (state.pendingReload && !state.sessionExpired) {
      state.pendingReload = false;
      await loadEstadoResultado();
    }
  }
}

function handleRequestError(error) {
  console.error(error);

  if (error?.code === 'SESSION_EXPIRED' || error?.code === 'INVALID_JSON_RESPONSE') {
    state.sessionExpired = true;
    showError('La sesión expiró. Serás redirigido al login.');
    updateStatus('Sesión expirada', 'warning');
    window.setTimeout(() => {
      window.location.href = './login.php?expired=1';
    }, 1200);
    return;
  }

  showError(error?.message || 'No se pudo cargar el Estado de Resultado.');
  updateStatus('Error al actualizar', 'warning');
}

function renderEstadoResultado(data) {
  state.columns = data.columns || [];
  state.rows = data.rows || [];

  document.getElementById('periodLabel').textContent = data.meta?.periodo || '-';
  renderKpis(data.kpis || {});
  renderTable(state.columns, state.rows);
}

function renderKpis(kpis) {
  document.getElementById('kpiIngreso').textContent = formatMoney(kpis.ingreso_total);
  document.getElementById('kpiMc').textContent = formatMoney(kpis.mc_total);
  document.getElementById('kpiMcPct').textContent = `${formatPercent(kpis.margen_mc_total)} sobre ingresos`;
  document.getElementById('kpiEbitda').textContent = formatMoney(kpis.ebitda_total);
  document.getElementById('kpiResultado').textContent = formatMoney(kpis.resultado_total);
  document.getElementById('kpiResultadoPct').textContent = `${formatPercent(kpis.margen_resultado_total)} sobre ingresos`;
}

function renderTable(columns, rows) {
  const table = document.getElementById('estadoResultadoTable');
  const thead = table.querySelector('thead');
  const tbody = table.querySelector('tbody');

  thead.innerHTML = `
    <tr>
      <th class="sticky-label">Concepto</th>
      ${columns.map((column) => `<th class="text-end">${escapeHtml(column)}</th>`).join('')}
    </tr>
  `;

  if (!rows.length) {
    tbody.innerHTML = `<tr><td colspan="${columns.length + 1}" class="text-center text-secondary py-4">Sin datos para el periodo seleccionado.</td></tr>`;
    return;
  }

  tbody.innerHTML = rows.map((row) => {
    const rowClasses = [
      row.is_calculated ? 'er-row-calculated' : '',
      row.type === 'percent' ? 'er-row-percent' : '',
      isSectionRow(row.label) ? 'er-row-section' : '',
    ].filter(Boolean).join(' ');

    return `
      <tr class="${rowClasses}">
        <td class="sticky-label fw-semibold">${escapeHtml(row.label)}</td>
        ${columns.map((column) => renderCell(row, column)).join('')}
      </tr>
    `;
  }).join('');
}

function renderCell(row, column) {
  const value = Number(row.values?.[column] || 0);
  const className = valueClass(value, row.type);
  const text = row.type === 'percent' ? formatPercent(value) : formatMoney(value);
  return `<td class="text-end ${className}">${text}</td>`;
}

function isSectionRow(label) {
  return [
    'Remuneraciones',
    'Ingresos No Operacionales',
    'Resultado Antes de Impuesto',
  ].includes(label);
}

function valueClass(value, type) {
  if (Math.abs(Number(value || 0)) < 0.000001) return 'er-zero';
  if (type === 'percent') return Number(value) < 0 ? 'er-negative' : 'er-positive';
  return Number(value) < 0 ? 'er-negative' : 'er-positive';
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

function updateStatus(text, variant = '') {
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

function formatMoney(value) {
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
