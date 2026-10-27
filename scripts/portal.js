// scripts/portal.js - API-driven portal (patient / doctor / super-admin)
// Replaces localStorage demo state with real backend database calls.
// All mutations require confirmModal + go through API endpoints (PDO, prepared statements).

import { api } from './api.js';
import { confirmModal, openModal, closeModal } from './modal.js';
import { showToast } from './toast.js';

let currentUser = null;
let role = '';
let dentistInfo = null;

async function loadSession() {
  const res = await api.get('auth/me.php');
  if (!res.success) return false;
  currentUser = res.data.user;
  role = currentUser.role;
  dentistInfo = res.data.dentist || null;
  return true;
}

const el = {
  sidebarToggleBtn: document.getElementById('sidebar-toggle-btn'),
  appSidebar: document.getElementById('app-sidebar'),
  btnProfileToggle: document.getElementById('btn-profile-toggle'),
  profileDropdownPanel: document.getElementById('profile-dropdown-panel'),
  btnNotifToggle: document.getElementById('btn-notif-toggle'),
  notifDropdownPanel: document.getElementById('notif-dropdown-panel'),
  notifCountBadge: document.getElementById('notif-count-badge'),
  notifListContainer: document.getElementById('notif-list-container'),
  patientServicesGrid: document.getElementById('patient-services-grid'),
  bookServiceSelect: document.getElementById('book-service'),
  bookDentistField: document.getElementById('book-dentist'),
  patientAppointmentsTbody: document.getElementById('patient-appointments-tbody'),
  patientRecordsTbody: document.getElementById('patient-records-tbody'),
  bookingForm: document.getElementById('booking-form'),
  patientProfileForm: document.getElementById('patient-profile-form'),
  changePasswordForm: document.getElementById('change-password-form'),
  adminTodayTbody: document.getElementById('admin-today-tbody'),
  adminAppointmentsTbody: document.getElementById('admin-appointments-tbody'),
  adminPatientsTbody: document.getElementById('admin-patients-tbody'),
  adminPatientsSearch: document.getElementById('admin-patient-search'),
  adminRecordsTbody: document.getElementById('admin-records-tbody'),
  adminServicesTbody: document.getElementById('admin-services-tbody'),
  kpiTotalPatients: document.getElementById('kpi-total-patients'),
  kpiTodayAppointments: document.getElementById('kpi-today-appointments'),
  kpiActiveDentists: document.getElementById('kpi-active-dentists'),
  kpiPendingRequests: document.getElementById('kpi-pending-requests'),
  modalBackdrop: document.getElementById('modal-backdrop'),
  toastContainer: document.getElementById('toast-container'),
};

async function initApp() {
  try {
    const ok = await loadSession();
    if (!ok) {
      // Session invalid or expired -> redirect to login
      window.location.assign('/draft for clinic/index.html');
      return;
    }
    bindEvents();
    renderRoleBasedNav();
    await renderNotifications();
    await renderDashboardIfRoleAdminOrDoctor();
    const activeView = document.querySelector('.page-view.active');
    if (activeView) await switchView(activeView.id.replace('view-', ''));
  } catch (err) {
    console.error('Init error:', err);
    showToast('Application failed to load. Please refresh and try again.', 'error');
  }
}

function renderRoleBasedNav() {
  const isAdmin = role === 'super-admin';
  document.querySelectorAll('.nav-link').forEach((link) => {
    const view = link.getAttribute('data-view') || '';
    const isAdminOnly = view.startsWith('admin-');
    const li = link.closest('li');
    if (isAdminOnly && !isAdmin && li) li.style.display = 'none';
  });
  const fullName = currentUser.full_name || '';
  const pName = document.getElementById('profile-fullname');
  const aName = document.getElementById('admin-profile-name');
  const pEmail = document.getElementById('profile-email');
  if (pName) pName.value = fullName;
  if (aName) aName.value = fullName;
  if (pEmail) pEmail.value = currentUser.email || '';
}

async function switchView(targetViewId) {
  const targetView = document.getElementById('view-' + targetViewId);
  if (!targetView) { showToast('This page is not available yet.', 'error'); return; }
  document.querySelectorAll('.page-view').forEach((v) => v.classList.remove('active'));
  document.querySelectorAll('.nav-link').forEach((l) => l.classList.remove('active'));
  targetView.classList.add('active');
  const navLink = document.querySelector('.nav-link[data-view="' + targetViewId + '"]');
  if (navLink) navLink.classList.add('active');
  closeDropdowns();
  if (el.appSidebar) el.appSidebar.classList.remove('show-sidebar');
  window.scrollTo({ top: 0, behavior: 'smooth' });
  switch (targetViewId) {
    case 'patient-home':
    case 'patient-services': await renderServices(); break;
    case 'patient-book': await renderServices(); await populateBookingDentist(); break;
    case 'patient-appointments': await renderPatientAppointments(); break;
    case 'patient-records': await renderPatientRecords(); break;
    case 'patient-dentists': await renderDentists(); break;
    case 'admin-dashboard': await renderDashboardIfRoleAdminOrDoctor(); await renderAdminAppointments(); break;
    case 'admin-appointments': await renderAdminAppointments(); break;
    case 'admin-patients': await renderAdminPatients(); break;
    case 'admin-records': await renderAdminRecords(); break;
    case 'admin-services': await renderAdminServices(); break;
    case 'admin-dentists': await renderAdminDentists(); break;
  }
}

async function renderServices() {
  const res = await api.get('services/list.php');
  if (!res.success) { showToast('Failed to load services.', 'error'); return; }
  const services = res.data || [];
  if (el.patientServicesGrid) {
    el.patientServicesGrid.innerHTML = services.map((s) => (
      '<div class="card">' +
      '<h3>' + escapeHtml(s.service_name) + '</h3>' +
      '<p class="text-sub mt-2">' + escapeHtml(s.description || '') + '</p>' +
      '<div class="service-price">₱' + Number(s.price || 0).toFixed(2) + '</div>' +
      '</div>'
    )).join('');
  }
  if (el.bookServiceSelect) {
    el.bookServiceSelect.innerHTML = '<option value="">-- Choose a Service --</option>' +
      services.map((s) => '<option value="' + s.id + '">' + escapeHtml(s.service_name) + '</option>').join('');
  }
  if (el.adminServicesTbody) {
    el.adminServicesTbody.innerHTML = services.map((s) => (
      '<tr>' +
      '<td><strong>' + escapeHtml(s.service_name) + '</strong></td>' +
      '<td>' + escapeHtml(s.description || '') + '</td>' +
      '<td>₱' + Number(s.price || 0).toFixed(2) + '</td>' +
      '<td><button class="btn btn-sm btn-danger" onclick="deleteService(' + s.id + ')">Delete</button></td>' +
      '</tr>'
    )).join('');
  }
}

async function renderDentists() {
  const res = await api.get('dentists/list.php');
  if (!res.success) { showToast('Failed to load dentists.', 'error'); return; }
  const dentists = res.data || [];
  if (el.bookDentistField) {
    const d = dentists[0];
    if (d) el.bookDentistField.value = d.full_name + ' (' + (d.specialization || 'General Dentistry') + ')';
  }
}
async function populateBookingDentist() { await renderDentists(); }

async function renderPatientAppointments() {
  const res = await api.get('appointments/mine.php');
  if (!res.success) { showToast('Failed to load appointments.', 'error'); return; }
  const appts = res.data || [];
  if (el.patientAppointmentsTbody) {
    if (appts.length === 0) {
      el.patientAppointmentsTbody.innerHTML = '<tr><td colspan="5" class="empty-cell">No appointments found.</td></tr>';
    } else {
      el.patientAppointmentsTbody.innerHTML = appts.map((a) => (
        '<tr>' +
        '<td>' + a.appointment_date + ' at ' + a.time_slot + '</td>' +
        '<td>' + escapeHtml(a.service_name || '') + '</td>' +
        '<td>' + escapeHtml(a.dentist_name || '') + '</td>' +
        '<td><span class="status-tag status-' + (a.status || 'Pending').toLowerCase() + '">' + a.status + '</span></td>' +
        '<td>' + (a.status === 'Pending' ? '<button class="btn btn-sm btn-outline" onclick="cancelApt(' + a.id + ')">Cancel</button>' : '-') + '</td>' +
        '</tr>'
      )).join('');
    }
  }
}

async function renderAdminAppointments() {
  const res = await api.get('appointments/all.php');
  if (!res.success) { showToast('Failed to load appointments.', 'error'); return; }
  const appts = res.data || [];
  if (el.adminAppointmentsTbody) {
    if (appts.length === 0) {
      el.adminAppointmentsTbody.innerHTML = '<tr><td colspan="6" class="empty-cell">No appointments found.</td></tr>';
    } else {
      el.adminAppointmentsTbody.innerHTML = appts.map((a) => {
        const statusClass = 'status-' + (a.status || 'Pending').toLowerCase();
        let actions;
        if (a.status === 'Pending') {
          actions = '<button class="btn btn-sm btn-primary" data-id="' + a.id + '" data-action="approve">Approve</button>' +
                    '<button class="btn btn-sm btn-outline" data-id="' + a.id + '" data-action="cancel">Cancel</button>';
        } else {
          actions = '<button class="btn btn-sm btn-primary" data-id="' + a.id + '" data-action="complete">Complete</button>' +
                    '<button class="btn btn-sm btn-outline" data-id="' + a.id + '" data-action="cancel">Cancel</button>';
        }
        return (
          '<tr>' +
          '<td>' + a.appointment_date + ' (' + a.time_slot + ')</td>' +
          '<td>' + escapeHtml(a.patient_name || '') + '</td>' +
          '<td>' + escapeHtml(a.service_name || '') + '</td>' +
          '<td>' + escapeHtml(a.dentist_name || '') + '</td>' +
          '<td><span class="status-tag ' + statusClass + '">' + a.status + '</span></td>' +
          '<td>' + actions + '</td>' +
          '</tr>'
        );
      }).join('');
    }
  }
  const today = new Date().toISOString().split('T')[0];
  const todayAppts = appts.filter((a) => a.appointment_date === today);
  if (el.adminTodayTbody) {
    if (todayAppts.length === 0) {
      el.adminTodayTbody.innerHTML = '<tr><td colspan="5" class="empty-cell">No appointments today.</td></tr>';
    } else {
      el.adminTodayTbody.innerHTML = todayAppts.map((a) => (
        '<tr>' +
        '<td>' + a.time_slot + '</td>' +
        '<td>' + escapeHtml(a.patient_name || '') + '</td>' +
        '<td>' + escapeHtml(a.service_name || '') + '</td>' +
        '<td>' + escapeHtml(a.dentist_name || '') + '</td>' +
        '<td><span class="status-tag status-' + (a.status || 'Pending').toLowerCase() + '">' + a.status + '</span></td>' +
        '</tr>'
      )).join('');
    }
  }
}

if (document.getElementById('booking-form')) {
  document.getElementById('booking-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const serviceId = document.getElementById('book-service').value;
    const dentistId = 1;
    const date = document.getElementById('book-date').value;
    const time = document.getElementById('book-time').value;
    const notes = document.getElementById('book-notes').value;
    if (!serviceId || !date || !time) { showToast('Please complete all booking fields.', 'error'); return; }
    const svcText = document.getElementById('book-service').selectedOptions[0]?.text || 'service';
    const confirmed = await confirmModal({ title: 'Confirm Appointment', message: 'Book ' + escapeHtml(svcText) + ' on ' + date + ' at ' + time + '?' });
    if (!confirmed) return;
    const res = await api.post('appointments/book.php', {
      service_id: parseInt(serviceId),
      dentist_id: dentistId,
      appointment_date: date,
      time_slot: time,
      notes,
    });
    if (res.success) {
      showToast('Appointment booked successfully!');
      document.getElementById('booking-form').reset();
      await switchView('patient-appointments');
    } else { showToast(res.message || 'Booking failed.', 'error'); }
  });
}

window.cancelApt = async (id) => {
  const confirmed = await confirmModal({ title: 'Cancel Appointment', message: 'Are you sure you want to cancel this appointment?', danger: true });
  if (!confirmed) return;
  const res = await api.patch('appointments/status.php', { id, status: 'Cancelled' });
  if (res.success) {
    showToast('Appointment cancelled.');
    await renderPatientAppointments();
    await renderAdminAppointments();
    await renderNotifications();
  } else { showToast(res.message || 'Failed to cancel.', 'error'); }
};

window.deleteService = async (id) => {
  const confirmed = await confirmModal({ title: 'Delete Service', message: 'Permanently delete this service?', danger: true });
  if (!confirmed) return;
  const res = await api.del('services/manage.php', { id });
  if (res.success) { showToast('Service deleted.'); await renderAdminServices(); await renderServices(); }
  else { showToast(res.message || 'Failed to delete.', 'error'); }
}

async function renderPatientRecords() {
  const res = await api.get('records/mine.php');
  if (!res.success) { showToast('Failed to load records.', 'error'); return; }
  const records = res.data || [];
  if (el.patientRecordsTbody) {
    if (records.length === 0) { el.patientRecordsTbody.innerHTML = '<tr><td colspan="4" class="empty-cell">No dental records.</td></tr>'; }
    else { el.patientRecordsTbody.innerHTML = records.map((r) => (
      '<tr>' +
      '<td>' + r.record_date + '</td>' +
      '<td>' + escapeHtml(r.treatment_procedure || '') + '</td>' +
      '<td>' + escapeHtml(r.clinical_notes || '') + '</td>' +
      '<td>' + escapeHtml(r.dentist_name || '') + '</td>' +
      '</tr>'
    )).join(''); }
  }
}

async function renderAdminRecords() {
  const res = await api.get('records/mine.php');
  if (!res.success) { showToast('Failed to load records.', 'error'); return; }
  const records = res.data || [];
  if (el.adminRecordsTbody) {
    if (records.length === 0) { el.adminRecordsTbody.innerHTML = '<tr><td colspan="5" class="empty-cell">No clinical records.</td></tr>'; }
    else { el.adminRecordsTbody.innerHTML = records.map((r) => (
      '<tr>' +
      '<td>' + r.record_date + '</td>' +
      '<td><strong>' + escapeHtml(r.patient_name || '') + '</strong></td>' +
      '<td>' + escapeHtml(r.treatment_procedure || '') + '</td>' +
      '<td>' + escapeHtml(r.clinical_notes || '') + '</td>' +
      '<td>' + escapeHtml(r.dentist_name || '') + '</td>' +
      '</tr>'
    )).join(''); }
  }
}

if (document.getElementById('btn-modal-record')) {
  document.getElementById('btn-modal-record').addEventListener('click', async () => {
    const usersRes = await api.get('users/list.php?role=patient');
    const patients = usersRes.success ? usersRes.data : [];
    const patientOptions = patients.map((p) => '<option value="' + p.id + '">' + escapeHtml(p.full_name) + ' (' + p.email + ')</option>').join('');
    const confirmed = await openModal('Add Clinical Record',
      '<form id="form-add-record">' +
      '<div class="form-group"><label>Patient</label><select id="m-rec-patient" required>' + patientOptions + '</select></div>' +
      '<div class="form-group"><label>Treatment / Procedure</label><input type="text" id="m-rec-treatment" required placeholder="e.g. Tooth Extraction"></div>' +
      '<div class="form-group"><label>Clinical Notes</label><textarea id="m-rec-notes" rows="3" required placeholder="Observations or prescribed care..."></textarea></div>' +
      '<div class="form-group"><label>Date</label><input type="date" id="m-rec-date" value="' + new Date().toISOString().split('T')[0] + '" required></div>' +
      '</form>',
      [ { text: 'Cancel', class: 'btn-outline', value: false }, { text: 'Save Record', class: 'btn-primary', value: true } ]);
    if (!confirmed) return;
    const treatment = document.getElementById('m-rec-treatment').value.trim();
    const notes = document.getElementById('m-rec-notes').value.trim();
    const patientId = document.getElementById('m-rec-patient').value;
    const date = document.getElementById('m-rec-date').value;
    const dentistId = dentistInfo ? dentistInfo.id : 1;
    if (!treatment || !notes || !patientId) { showToast('Please fill all fields.', 'error'); return; }
    const res = await api.post('records/add.php', {
      patient_id: parseInt(patientId),
      dentist_id: parseInt(dentistId),
      treatment_procedure: treatment,
      clinical_notes: notes,
      record_date: date,
    });
    if (res.success) { showToast('Clinical record added.'); closeModal(); await renderAdminRecords(); await renderPatientRecords(); }
    else { showToast(res.message || 'Failed to add record.', 'error'); }
  });
}

async function renderNotifications() {
  const res = await api.get('notifications/list.php');
  if (!res.success) return;
  const { notifications, unread_count } = res.data;
  if (el.notifCountBadge) { el.notifCountBadge.textContent = unread_count; el.notifCountBadge.classList.toggle('hidden', unread_count === 0); }
  if (el.notifListContainer) {
    if (notifications.length === 0) { el.notifListContainer.innerHTML = '<li class="empty-list-text">No new notifications.</li>'; }
    else { el.notifListContainer.innerHTML = notifications.map((n) => (
      '<li style="padding:0.6rem 1rem;border-bottom:1px solid var(--border-color);font-size:0.82rem;">' +
      '<div>' + escapeHtml(n.message) + '</div>' +
      '<span class="text-sub" style="font-size:0.72rem;">' + n.created_at + '</span>' +
      '</li>'
    )).join(''); }
  }
}

async function renderDashboardIfRoleAdminOrDoctor() {
  if (role !== 'super-admin' && role !== 'doctor') return;
  const res = await api.get('dashboard/stats.php');
  if (!res.success) return;
  const k = res.data;
  if (el.kpiTotalPatients) el.kpiTotalPatients.textContent = k.total_patients ?? 0;
  if (el.kpiTodayAppointments) el.kpiTodayAppointments.textContent = k.today_appointments ?? 0;
  if (el.kpiActiveDentists) el.kpiActiveDentists.textContent = k.active_dentists ?? 0;
  if (el.kpiPendingRequests) el.kpiPendingRequests.textContent = k.pending_requests ?? 0;
}

async function renderAdminPatients() {
  const res = await api.get('users/list.php?role=patient');
  if (!res.success) { showToast('Failed to load patients.', 'error'); return; }
  const patients = res.data || [];
  if (el.adminPatientsTbody) {
    const q = el.adminPatientsSearch ? el.adminPatientsSearch.value.toLowerCase() : '';
    const filtered = patients.filter((p) => p.full_name.toLowerCase().includes(q) || p.email.toLowerCase().includes(q));
    if (filtered.length === 0) { el.adminPatientsTbody.innerHTML = '<tr><td colspan="5" class="empty-cell">No patients found.</td></tr>'; }
    else { el.adminPatientsTbody.innerHTML = filtered.map((p) => (
      '<tr>' +
      '<td>' + p.id + '</td>' +
      '<td><strong>' + escapeHtml(p.full_name) + '</strong></td>' +
      '<td>' + escapeHtml(p.email) + '</td>' +
      '<td>' + escapeHtml(p.phone_number || '') + '</td>' +
      '<td>' + p.created_at + '</td>' +
      '</tr>'
    )).join(''); }
  }
}
if (document.getElementById('admin-patient-search')) {
  document.getElementById('admin-patient-search').addEventListener('input', renderAdminPatients);
}
async function renderAdminServices() { await renderServices(); }
async function renderAdminDentists() {
  const res = await api.get('dentists/list.php');
  if (!res.success) return;
  const dentists = res.data || [];
  const container = document.getElementById('admin-dentists-list');
  if (!container) return;
  container.innerHTML = dentists.map((d) => (
    '<tr>' +
    '<td>' + d.id + '</td>' +
    '<td>' + escapeHtml(d.full_name) + '</td>' +
    '<td>' + escapeHtml(d.specialization || '') + '</td>' +
    '<td>' + escapeHtml(d.phone_number || '') + '</td>' +
    '<td>' + (d.role || 'doctor') + '</td>' +
    '</tr>'
  )).join('');
}

if (el.patientProfileForm) {
  el.patientProfileForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const fullName = document.getElementById('profile-fullname').value.trim();
      const phone = document.getElementById('profile-phone').value.trim();
      const confirmed = await confirmModal({ title: 'Update Profile', message: 'Save profile changes?' });
      if (!confirmed) return;
      const res = await api.patch('auth/profile.php', { full_name: fullName, phone_number: phone });
      if (res.success) { showToast('Profile updated.'); currentUser.full_name = fullName; }
      else { showToast(res.message || 'Update failed.', 'error'); }
    } catch (err) {
      console.error('Profile update error:', err);
      showToast('Profile update failed. Please try again.', 'error');
    }
  });
}

if (document.getElementById('change-password-form')) {
  document.getElementById('change-password-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const curr = document.getElementById('curr-password').value;
      const newPass = document.getElementById('new-password').value;
      const confirmed = await confirmModal({ title: 'Change Password', message: 'Change your account password?' });
      if (!confirmed) return;
      const res = await api.post('auth/password.php', { current_password: curr, new_password: newPass });
      if (res.success) { showToast('Password changed.'); document.getElementById('change-password-form').reset(); }
      else { showToast(res.message || 'Password change failed.', 'error'); }
    } catch (err) {
      console.error('Password change error:', err);
      showToast('Password change failed. Please try again.', 'error');
    }
  });
}

function bindEvents() {
  if (el.sidebarToggleBtn) el.sidebarToggleBtn.addEventListener('click', () => el.appSidebar.classList.toggle('show-sidebar'));
  document.querySelectorAll('.nav-link').forEach((link) => {
    link.addEventListener('click', (e) => { e.preventDefault(); switchView(link.getAttribute('data-view')); });
  });
  document.querySelectorAll('.btn-logout-action').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const confirmed = await confirmModal({ title: 'Log Out', message: 'Are you sure you want to log out?', danger: true });
      if (!confirmed) return;
      await api.post('auth/logout.php');
      window.location.assign('/draft for clinic/index.html');
    });
  });
  if (el.btnProfileToggle) el.btnProfileToggle.addEventListener('click', (e) => { e.stopPropagation(); el.profileDropdownPanel.classList.toggle('hidden'); el.notifDropdownPanel.classList.add('hidden'); });
  if (el.btnNotifToggle) el.btnNotifToggle.addEventListener('click', (e) => { e.stopPropagation(); el.notifDropdownPanel.classList.toggle('hidden'); el.profileDropdownPanel.classList.add('hidden'); });
  document.addEventListener('click', closeDropdowns);
  document.querySelectorAll('.dropdown-item').forEach((item) => {
    item.addEventListener('click', (e) => {
      e.preventDefault();
      const target = item.getAttribute('data-view-target');
      const prefix = role !== 'patient' ? 'admin-' : 'patient-';
      switchView(prefix + target);
    });
  });
  // Delegation: admin appointment action buttons (data-id / data-action)
  if (el.adminAppointmentsTbody) {
    el.adminAppointmentsTbody.addEventListener('click', async (e) => {
      const btn = e.target.closest('button[data-id][data-action]');
      if (!btn) return;
      const id = btn.getAttribute('data-id');
      const action = btn.getAttribute('data-action');
      const statusMap = { approve: 'Approved', complete: 'Completed', cancel: 'Cancelled' };
      const newStatus = statusMap[action];
      if (!newStatus) return;
      const confirmed = await confirmModal({ title: 'Confirm Status Change', message: 'Change appointment to "' + newStatus + '"?', danger: action === 'cancel' });
      if (!confirmed) return;
      const res = await api.patch('appointments/status.php', { id: parseInt(id), status: newStatus });
      if (res.success) {
        showToast('Appointment ' + newStatus.toLowerCase() + '.');
        await renderPatientAppointments();
        await renderAdminAppointments();
        await renderDashboardIfRoleAdminOrDoctor();
        await renderNotifications();
      } else { showToast(res.message || 'Failed to update.', 'error'); }
    });
  }
}
function closeDropdowns() { if (el.profileDropdownPanel) el.profileDropdownPanel.classList.add('hidden'); if (el.notifDropdownPanel) el.notifDropdownPanel.classList.add('hidden'); }
function escapeHtml(text) { const div = document.createElement('div'); div.textContent = text ?? ''; return div.innerHTML; }

// Global error handlers — prevent crashes from breaking the page
window.addEventListener('error', (e) => {
  console.error('Global JS error:', e.message, e.filename, e.lineno);
  showToast('An error occurred: ' + e.message, 'error');
});
window.addEventListener('unhandledrejection', (e) => {
  console.error('Unhandled rejection:', e.reason);
  showToast('An error occurred: ' + String(e.reason), 'error');
});

initApp();
