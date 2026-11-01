// scripts/modules/activity.js - the activity log page.
//
// Extracted from portal.js. Owns its own pagination and filter state, so the
// host file no longer needs to know anything about it beyond initActivityLog().

import { api, showToast, escapeHtml } from '../portal-context.js';

/** Readable labels for the dotted action names written by the API. */
const ACTIVITY_LABELS = {
  'auth.login': 'Signed in',
  'auth.logout': 'Signed out',
  'auth.login_blocked_ip': 'Blocked administrator sign-in',
  'auth.password_reset_requested': 'Requested a password reset',
  'auth.password_reset_completed': 'Reset a password',
  'appointment.create': 'Booked appointment',
  'appointment.status': 'Changed appointment status',
  'appointment.reschedule': 'Rescheduled appointment',
  'service.create': 'Created service',
  'service.update': 'Updated service',
  'service.deactivate': 'Deactivated service',
  'service.archive': 'Archived service',
  'user.create': 'Created account',
  'user.update': 'Updated account',
  'user.signup': 'Registered account',
  'record.create': 'Added clinical record',
  'record.complete': 'Completed clinical record',
  'record.archive': 'Archived record',
  'archive.restore': 'Restored record',
  'archive.purge': 'Permanently deleted record',
  'settings.update': 'Updated clinic details',
  'settings.admin_ip_added': 'Added allowlist address',
  'settings.admin_ip_updated': 'Changed allowlist address',
  'settings.admin_ip_removed': 'Removed allowlist address',
  'schedule.update': 'Updated clinic hours',
  'availability.update': 'Updated availability',
};

const state = { page: 1, pages: 0, filters: {} };

function tone(action) {
  if (action === 'archive.purge' || action === 'service.archive' || action === 'record.archive') return 'danger';
  if (action.includes('create') || action.includes('restore')) return 'success';
  if (action.startsWith('auth.')) return 'neutral';
  return 'info';
}

/** Load and paint one page of the activity log. */
export async function renderActivityLog() {
  const tbody = document.getElementById('activity-tbody');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="5" class="empty-cell">Loading activity\u2026</td></tr>';

  const query = new URLSearchParams({ page: String(state.page), per_page: '25' });
  Object.entries(state.filters).forEach(([key, value]) => {
    if (value) query.set(key, value);
  });

  const res = await api.get('activity/list.php?' + query.toString());
  if (!res.success) {
    tbody.innerHTML = '<tr><td colspan="5" class="empty-cell">Could not load the activity log.</td></tr>';
    showToast(res.message || 'Could not load the activity log.', 'error');
    return;
  }

  const { rows, total, page, pages, actions } = res.data;

  // Keep the action filter in step with what actually exists in the log.
  const actionSelect = document.getElementById('activity-action');
  if (actionSelect && actions) {
    const current = actionSelect.value;
    actionSelect.innerHTML = '<option value="">All actions</option>' +
      actions.map((a) => '<option value="' + escapeHtml(a) + '">' +
        escapeHtml(ACTIVITY_LABELS[a] || a) + '</option>').join('');
    actionSelect.value = current;
  }

  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="5" class="empty-cell">No activity matches these filters.</td></tr>';
  } else {
    tbody.innerHTML = rows.map((row) => {
      const when = String(row.created_at || '').replace('T', ' ');
      const label = ACTIVITY_LABELS[row.action] || row.action;
      return '<tr>' +
        '<td class="activity-when">' + escapeHtml(when) + '</td>' +
        '<td>' + escapeHtml(row.user_name || 'System') +
          '<br><span class="text-sub activity-role">' + escapeHtml(row.user_role || '') + '</span></td>' +
        '<td><span class="activity-badge activity-' + tone(row.action) + '">' + escapeHtml(label) + '</span></td>' +
        '<td class="activity-description">' + escapeHtml(row.description || '') + '</td>' +
        '<td class="activity-ip">' + escapeHtml(row.ip_address || '') + '</td>' +
        '</tr>';
    }).join('');
  }

  state.pages = pages || 0;
  const info = document.getElementById('activity-page-info');
  if (info) {
    info.textContent = total === 0
      ? 'No entries'
      : 'Page ' + page + ' of ' + pages + ' \u00b7 ' + total + ' entr' + (total === 1 ? 'y' : 'ies');
  }
  const prev = document.getElementById('btn-activity-prev');
  const next = document.getElementById('btn-activity-next');
  if (prev) prev.disabled = page <= 1;
  if (next) next.disabled = page >= pages;
}

/** Reset to page 1 and reload with the current filter values. */
export function applyActivityFilters() {
  state.page = 1;
  state.filters = {
    from: document.getElementById('activity-from')?.value || '',
    to: document.getElementById('activity-to')?.value || '',
    action: document.getElementById('activity-action')?.value || '',
    q: (document.getElementById('activity-search')?.value || '').trim(),
  };
  return renderActivityLog();
}

/** Clear every filter and reload. */
export function clearActivityFilters() {
  ['activity-from', 'activity-to', 'activity-action', 'activity-search'].forEach((id) => {
    const input = document.getElementById(id);
    if (input) input.value = '';
  });
  state.filters = {};
  state.page = 1;
  return renderActivityLog();
}

export function activityNextPage() {
  if (state.page < state.pages) {
    state.page += 1;
    return renderActivityLog();
  }
  return Promise.resolve();
}

export function activityPrevPage() {
  if (state.page > 1) {
    state.page -= 1;
    return renderActivityLog();
  }
  return Promise.resolve();
}

/** Wire the activity log panel. Called once from portal.js. */
export function initActivityLog() {
  const filterButton = document.getElementById('btn-filter-activity');
  if (filterButton) filterButton.addEventListener('click', applyActivityFilters);

  // Enter inside the search box applies the filters, matching the old behaviour.
  const searchInput = document.getElementById('activity-search');
  if (searchInput) {
    searchInput.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') { event.preventDefault(); applyActivityFilters(); }
    });
  }

  const clearButton = document.getElementById('btn-clear-activity');
  if (clearButton) clearButton.addEventListener('click', clearActivityFilters);

  const prev = document.getElementById('btn-activity-prev');
  if (prev) prev.addEventListener('click', activityPrevPage);

  const next = document.getElementById('btn-activity-next');
  if (next) next.addEventListener('click', activityNextPage);
}
