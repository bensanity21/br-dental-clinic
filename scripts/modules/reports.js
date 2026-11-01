// scripts/modules/reports.js - report generation and CSV export.
//
// Extracted from portal.js. Everything it needs is imported, so it has no
// hidden dependency on portal.js internals.
//
// The CSV export is generated server-side (api/reports/export.php) so the
// browser never has to build a file; this module only triggers the download.

import { api, showToast, confirmModal, escapeHtml } from '../portal-context.js';

/** Column header -> row key. Shared with the on-screen table and the CSV. */
const HEADER_KEYS = {
  'Status': 'status',
  'Appointments': 'total',
  'Service': 'service_name',
  'Dentist': 'full_name',
  'Patient ID': 'id',
  'Full Name': 'full_name',
  'Email': 'email',
  'Registered': 'created_at',
};

function keysForHeaders(headers) {
  return headers.map((header) =>
    HEADER_KEYS[header] || header.toLowerCase().replace(/\s+/g, '_')
  );
}

/** Build the totals row shown under a report. */
function totalsRow(totals, rows, keys) {
  if (!totals || !rows.length) return '';

  let value = '';
  if (totals.column) {
    value = String(rows.reduce((sum, row) => sum + Number(row[totals.column] || 0), 0));
  } else if (totals.count !== undefined) {
    value = String(totals.count);
  }
  if (value === '') return '';

  return '<tr class="report-total-row">' +
    '<td>' + escapeHtml(totals.label) + '</td>' +
    '<td>' + escapeHtml(value) + '</td>' +
    keys.slice(2).map(() => '<td></td>').join('') +
    '</tr>';
}

/** Render the selected report into the results panel. */
export async function renderReport() {
  const from = document.getElementById('report-from')?.value || '';
  const to = document.getElementById('report-to')?.value || '';
  const type = document.getElementById('report-type')?.value || 'appointments';
  const results = document.getElementById('report-results-body');

  if (!from || !to) { showToast('Choose both a start date and an end date.', 'error'); return; }
  if (from > to) { showToast('The start date must be on or before the end date.', 'error'); return; }

  const button = document.getElementById('btn-run-report');
  if (button) button.disabled = true;

  try {
    const query = new URLSearchParams({ from, to, type });
    const res = await api.get('reports/generate.php?' + query.toString());
    if (!res.success) { showToast(res.message || 'Failed to generate report.', 'error'); return; }

    const headers = res.data?.headers || [];
    const rows = res.data?.data || [];
    const totals = res.data?.totals || null;
    const title = res.data?.title || 'Report';
    const keys = keysForHeaders(headers);

    const bodyHtml = rows.map((row) => '<tr>' +
      keys.map((key) => '<td>' + escapeHtml(row[key] ?? '') + '</td>').join('') +
      '</tr>').join('');

    if (results) {
      results.innerHTML =
        '<div class="report-block">' +
          '<h3 class="report-block-title">' + escapeHtml(title) + '</h3>' +
          '<p class="report-block-period">' + escapeHtml(from) + ' to ' + escapeHtml(to) + '</p>' +
          (rows.length
            ? '<div class="table-scroll"><table class="data-table report-table">' +
                '<thead><tr>' + headers.map((h) => '<th>' + escapeHtml(h) + '</th>').join('') + '</tr></thead>' +
                '<tbody>' + bodyHtml + totalsRow(totals, rows, keys) + '</tbody>' +
              '</table></div>'
            : '<p class="text-sub text-center">No records found for the selected dates.</p>') +
          '<p class="report-generated-note">Generated ' + escapeHtml(new Date().toLocaleString()) + '</p>' +
        '</div>';
    }
  } finally {
    if (button) button.disabled = false;
  }
}

/** Download the current report as CSV, after confirmation. */
export async function exportReportCsv() {
  const from = document.getElementById('report-from')?.value || '';
  const to = document.getElementById('report-to')?.value || '';
  const type = document.getElementById('report-type')?.value || 'appointments';

  if (!from || !to) { showToast('Choose both a start date and an end date.', 'error'); return; }
  if (from > to) { showToast('The start date must be on or before the end date.', 'error'); return; }

  const confirmed = await confirmModal({
    title: 'Export this report?',
    message: 'A CSV file for ' + from + ' to ' + to + ' will be downloaded.',
    confirmLabel: 'Download',
  });
  if (!confirmed) return;

  // A temporary anchor is more reliable than location.assign: it never
  // navigates away from the page and survives pop-blocking.
  const query = new URLSearchParams({ from, to, type });
  const link = document.createElement('a');
  link.href = new URL('api/reports/export.php?' + query.toString(), document.baseURI).href;
  link.setAttribute('download', '');
  link.style.display = 'none';
  document.body.appendChild(link);
  link.click();
  link.remove();
  showToast('Your CSV download has started.');
}

/** Wire the reports panel buttons. Called once from portal.js. */
export function initReports() {
  const runButton = document.getElementById('btn-run-report');
  if (runButton) runButton.addEventListener('click', renderReport);

  const exportButton = document.getElementById('btn-export-report');
  if (exportButton) exportButton.addEventListener('click', exportReportCsv);
}
