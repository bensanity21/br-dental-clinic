// scripts/modules/users.js - the Users & Roles page.
//
// Extracted from portal.js. Covers listing accounts, the create-account modal
// and the archive page, which also deals in records rather than only users.

import { api, showToast, openModal, escapeHtml } from '../portal-context.js';
import { confirmModal } from '../portal-context.js';

const ROLE_LABELS = { 'super-admin': 'Super Admin', doctor: 'Dentist', patient: 'Patient' };

// ---------------------------------------------------------------- users list

/** Paint the users table. */
export async function renderAdminUsers() {
  const tbody = document.getElementById('admin-users-body');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="5">Loading users\u2026</td></tr>';

  const res = await api.get('users/list.php');
  if (!res.success) {
    tbody.innerHTML = '<tr><td colspan="5">Could not load users.</td></tr>';
    showToast(res.message || 'Failed to load users.', 'error');
    return;
  }

  const users = res.data || [];
  if (!users.length) {
    tbody.innerHTML = '<tr><td colspan="5">No accounts yet.</td></tr>';
    return;
  }

  tbody.innerHTML = users.map((u) => '<tr>' +
    '<td>' + escapeHtml(u.full_name) + '</td>' +
    '<td>' + escapeHtml(u.email) + '</td>' +
    '<td>' + escapeHtml(ROLE_LABELS[u.role] || u.role) + '</td>' +
    '<td>' + escapeHtml(u.phone_number || 'Not provided') + '</td>' +
    '<td>' + escapeHtml((u.created_at || '').slice(0, 10)) + '</td>' +
    '</tr>').join('');
}

/** Open the create-account modal. Only dentists and patients can be created. */
export function openCreateUserModal() {
  const body = '' +
    '<div class="form-group"><label for="new-user-name">Full name</label>' +
    '<input type="text" id="new-user-name" placeholder="Juan Dela Cruz" required></div>' +
    '<div class="form-group"><label for="new-user-email">Email</label>' +
    '<input type="email" id="new-user-email" placeholder="name@example.com" required></div>' +
    '<div class="form-group"><label for="new-user-role">Role</label>' +
    '<select id="new-user-role"><option value="doctor">Dentist</option>' +
    '<option value="patient">Patient</option></select></div>' +
    '<div class="form-group"><label for="new-user-phone">Phone number (optional)</label>' +
    '<input type="text" id="new-user-phone" placeholder="09XXXXXXXXX"></div>' +
    '<div class="form-group"><label for="new-user-password">Temporary password</label>' +
    '<input type="password" id="new-user-password" placeholder="At least 8 characters" required></div>';

  openModal('Create account', body, [
    { text: 'Cancel', value: false, class: 'btn-outline' },
    {
      text: 'Create account',
      class: 'btn-primary',
      value: async () => {
        const payload = {
          full_name: document.getElementById('new-user-name')?.value.trim() || '',
          email: document.getElementById('new-user-email')?.value.trim() || '',
          role: document.getElementById('new-user-role')?.value || 'patient',
          phone_number: document.getElementById('new-user-phone')?.value.trim() || '',
          password: document.getElementById('new-user-password')?.value || '',
        };
        if (!payload.full_name || !payload.email || payload.password.length < 8) {
          showToast('Full name, email and a password of at least 8 characters are required.', 'error');
          return false;
        }
        const res = await api.post('users/create.php', payload);
        if (res.success) {
          showToast('Account created.');
          await renderAdminUsers();
        } else {
          showToast(res.message || 'Could not create the account.', 'error');
        }
        return false;
      },
    },
  ]);
}

// ------------------------------------------------------------------ archives

/** Paint the archive table. Entries arrive as a JSON snapshot of the row. */
export async function renderArchives() {
  const tbody = document.getElementById('admin-archives-tbody');
  if (!tbody) return;

  const res = await api.get('archives/list.php');
  if (!res.success) { showToast(res.message || 'Failed to load archive.', 'error'); return; }

  const entries = res.data || [];
  if (!entries.length) {
    tbody.innerHTML = '<tr><td colspan="4" class="empty-cell">The archive is empty.</td></tr>';
    return;
  }

  tbody.innerHTML = entries.map((entry) => {
    const record = entry.record_data || {};
    let summary;
    if (entry.original_table === 'appointments') {
      summary = [record.appointment_date, record.time_slot, 'Patient ID ' + (record.patient_id ?? ''), record.status].filter(Boolean).join(' \u00b7 ');
    } else if (entry.original_table === 'users') {
      summary = [record.full_name, record.email].filter(Boolean).join(' \u00b7 ');
    } else if (entry.original_table === 'services') {
      summary = record.service_name || '';
    } else {
      summary = record.full_name || record.treatment_procedure || ('Record ID ' + entry.record_id);
    }
    return '<tr>' +
      '<td>' + escapeHtml(String(entry.original_table || '').replaceAll('_', ' ')) + '</td>' +
      '<td>' + escapeHtml(summary) + '</td>' +
      '<td>' + escapeHtml(entry.deleted_at || '') + '</td>' +
      '<td><button type="button" class="btn btn-sm btn-primary" data-archive-restore="' + Number(entry.id) + '">Restore</button> ' +
      '<button type="button" class="btn btn-sm btn-danger" data-archive-purge="' + Number(entry.id) + '">Delete permanently</button></td>' +
      '</tr>';
  }).join('');
}

// ------------------------------------------------------------------- wiring

/** Wire the Users & Roles page and the archive buttons.
 *
 *  onRestored is called after a successful restore because the archive page can
 *  bring back an appointment or a clinical record, which the other admin tables
 *  also show. portal.js passes a refresher so those tables stay in step.
 */
export function initUsers({ onRestored } = {}) {
  const createButton = document.getElementById('btn-create-user');
  if (createButton) createButton.addEventListener('click', openCreateUserModal);

  const archiveBody = document.getElementById('admin-archives-tbody');
  if (!archiveBody) return;

  archiveBody.addEventListener('click', async (event) => {
    const restoreButton = event.target.closest('button[data-archive-restore]');
    const purgeButton = event.target.closest('button[data-archive-purge]');

    if (restoreButton) {
      const id = Number(restoreButton.getAttribute('data-archive-restore'));
      const confirmed = await confirmModal({
        title: 'Restore Archived Record',
        message: 'Restore this record from the archive?',
        confirmLabel: 'Restore',
      });
      if (!confirmed) return;

      const res = await api.post('archives/restore.php', { id });
      if (!res.success) { showToast(res.message || 'Could not restore this record.', 'error'); return; }
      showToast('Record restored.');
      await renderArchives();
      if (typeof onRestored === 'function') await onRestored();
      return;
    }

    if (purgeButton) {
      const id = Number(purgeButton.getAttribute('data-archive-purge'));
      const confirmed = await confirmModal({
        title: 'Delete Permanently',
        message: 'This permanently deletes the archived record and cannot be undone. Continue?',
        confirmLabel: 'Delete permanently',
        danger: true,
      });
      if (!confirmed) return;

      // The API requires the literal word DELETE as a second safeguard.
      const res = await api.post('archives/hard-delete.php', { id, confirm: 'DELETE' });
      if (!res.success) { showToast(res.message || 'Could not permanently delete this record.', 'error'); return; }
      showToast('Archived record permanently deleted.');
      await renderArchives();
    }
  });
}
