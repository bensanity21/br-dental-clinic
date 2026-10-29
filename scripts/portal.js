// scripts/portal.js - API-driven portal (patient / doctor / super-admin)
// Replaces localStorage demo state with real backend database calls.
// All mutations require confirmModal + go through API endpoints (PDO, prepared statements).

import { api, setCsrfToken } from './api.js?v=7';
import { confirmModal, openModal, closeModal } from './modal.js?v=8';
import { showToast } from './toast.js';

let currentUser = null;
let role = '';
let dentistInfo = null;
let serviceCatalog = [];
let knownNotificationIds = null;
let notificationsRequestInFlight = false;
let lastNotificationSnapshot = null;

async function loadSession() {
  const res = await api.get('auth/me.php');
  if (!res.success) return false;
  setCsrfToken(res.data.csrf_token);
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
  patientServicesTbody: document.getElementById('patient-services-tbody'),
  patientServiceSearch: document.getElementById('patient-service-search'),
  adminServiceSearch: document.getElementById('admin-service-search'),
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
      window.location.assign(new URL('./index.html', document.baseURI).href);
      return;
    }
    renderNavigation(); // Inject sidebar + top navbar HTML
    bindEvents();
    renderRoleBasedNav();
    await renderNotifications();
    startNotificationPolling();
    await renderDashboardIfRoleAdminOrDoctor();
    const activeView = document.querySelector('.page-view.active');
    if (activeView) await switchView(activeView.id.replace('view-', ''));
  } catch (err) {
    console.error('Init error:', err);
    showToast('Application failed to load. Please refresh and try again.', 'error');
  }
}

/**
 * Injects the sidebar and top navigation bar HTML into the slot divs.
 * The HTML references IDs that portal.js bindings expect (sidebar-toggle-btn,
 * btn-profile-toggle, btn-notif-toggle, notif-count-badge, etc.).
 */
function renderNavigation() {
  const sidebarSlot = document.getElementById('sidebar-slot');
  const navbarSlot = document.getElementById('navbar-slot');
  if (!sidebarSlot || !navbarSlot) return;

  const isAdmin = role === 'super-admin';
  const isDoctor = role === 'doctor';
  const fullName = currentUser.full_name || 'User';
  const firstName = fullName.split(' ')[0] || 'User';

  // ---------- Sidebar ----------
  sidebarSlot.innerHTML = `
    <aside class="sidebar" id="app-sidebar" aria-label="Clinic navigation">
      <div class="sidebar-header">
        <div class="clinic-brand">
          <img class="brand-mark" src="brand-icon.png" alt="" width="32" height="32">
          <span class="brand-name">BR Dental Clinic</span>
        </div>
        <button type="button" class="btn-icon sidebar-toggle-btn" id="sidebar-toggle-btn" aria-label="Toggle navigation" aria-controls="app-sidebar" aria-expanded="false">
          <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
        </button>
      </div>

      <nav class="sidebar-nav" aria-label="Main navigation">
        <ul>
          <li>
            <a href="#" class="nav-link${!isAdmin ? ' active' : ''}" data-view="${isAdmin ? 'admin-dashboard' : 'patient-home'}">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1" ry="1"></rect><rect x="14" y="3" width="7" height="7" rx="1" ry="1"></rect><rect x="14" y="14" width="7" height="7" rx="1" ry="1"></rect><rect x="3" y="14" width="7" height="7" rx="1" ry="1"></rect></svg>
              <span>Dashboard</span>
            </a>
          </li>
          ${!isAdmin ? `
          <li>
            <a href="#" class="nav-link" data-view="patient-services">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="18" height="14" rx="2"></rect><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 12h18M10 12v2h4v-2"></path></svg>
              <span>Services</span>
            </a>
          </li>
          <li>
            <a href="#" class="nav-link" data-view="patient-appointments">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="17" rx="2"></rect><path d="M8 2v4M16 2v4M3 9h18"></path><rect x="8" y="13" width="3" height="3" rx="0.4"></rect></svg>
              <span>Appointment</span>
            </a>
          </li>
          ` : `
          <li>
            <a href="#" class="nav-link" data-view="admin-appointments">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="17" rx="2"></rect><path d="M8 2v4M16 2v4M3 9h18"></path><rect x="8" y="13" width="3" height="3" rx="0.4"></rect></svg>
              <span>Appointments</span>
            </a>
          </li>
          <li>
            <a href="#" class="nav-link" data-view="admin-patients">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.47M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
              <span>Patients</span>
            </a>
          </li>
          <li>
            <a href="#" class="nav-link" data-view="admin-records">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
              <span>Dental Records</span>
            </a>
          </li>
          <li>
            <a href="#" class="nav-link" data-view="admin-services">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="18" height="14" rx="2"></rect><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 12h18M10 12v2h4v-2"></path></svg>
              <span>Services</span>
            </a>
          </li>
          <li>
            <a href="#" class="nav-link" data-view="admin-reports">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><line x1="1" y1="1" x2="23" y2="23"></line><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
              <span>Reports</span>
            </a>
          </li>
          `}
          ${isAdmin ? `
          <li>
            <a href="#" class="nav-link" data-view="admin-users">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.47M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
              <span>Users & Roles</span>
            </a>
          </li>
          <li>
            <a href="#" class="nav-link" data-view="admin-archives">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 4h18v4H3z"></path><path d="M5 8v12h14V8M10 12h4"></path></svg>
              <span>Archive</span>
            </a>
          </li>
          ` : ''}
        </ul>
      </nav>

    </aside>
  `;
  updateSidebarAccessibility();

  // ---------- Top Navbar ----------
  navbarSlot.innerHTML = `
    <header class="top-navbar" id="top-navbar">
      <div class="navbar-search">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" class="search-icon"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
        <input type="search" id="global-search-input" placeholder="Search anything..." aria-label="Search clinic information" aria-controls="global-search-results" aria-expanded="false" autocomplete="off">
        <div id="global-search-results" class="global-search-results hidden" role="region" aria-label="Search results" aria-live="polite"></div>
      </div>

      <div class="navbar-right">
        <div class="navbar-item">
          <button type="button" class="btn-icon btn-notif-toggle" id="btn-notif-toggle" aria-label="Notifications" aria-controls="notif-dropdown-panel" aria-expanded="false" style="position: relative;">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
            <span id="notif-count-badge" class="badge-count hidden" aria-hidden="true">0</span>
          </button>
          <div id="notif-dropdown-panel" class="dropdown-panel dropdown-right hidden" role="region" aria-labelledby="notif-dropdown-heading" aria-hidden="true" style="position: absolute; top: 100%; right: 0; width: 320px; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1); margin-top: 0.5rem; z-index: 100;">
            <div class="dropdown-header" style="padding: 0.75rem 1rem; border-bottom: 1px solid #f3f4f6;">
              <h3 id="notif-dropdown-heading" style="margin: 0; font-size: 0.875rem; font-weight: 600; color: #111827;">Notifications</h3>
            </div>
            <ul id="notif-list-container" class="dropdown-list" style="list-style: none; margin: 0; padding: 0; max-height: 240px; overflow-y: auto;">
              <li class="empty-list-text" style="padding: 0.75rem 1rem; color: #6b7280; font-size: 0.875rem;">No new notifications.</li>
            </ul>
            <div style="padding: 0.5rem 1rem; border-top: 1px solid #f3f4f6;">
              <button type="button" class="btn btn-outline" style="width: 100%; padding: 0.5rem 1rem; font-size: 0.875rem;" id="btn-mark-all-read">Mark all as read</button>
            </div>
          </div>
        </div>

        <div class="navbar-item">
          <button type="button" class="profile-menu-toggle" id="btn-profile-toggle" aria-label="Open account menu" aria-expanded="false">
            <span class="profile-menu-avatar" aria-hidden="true"><svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2.4"><circle cx="12" cy="8" r="3.5"></circle><path d="M5 20c.4-3.5 2.7-5.5 7-5.5s6.6 2 7 5.5"></path></svg></span>
            <span class="profile-menu-name">${escapeHtml(fullName)}</span>
            <svg class="profile-menu-chevron" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
          </button>
          <div id="profile-dropdown-panel" class="dropdown-panel dropdown-right profile-dropdown-card hidden" role="region" aria-label="Account options" aria-hidden="true">
            <div class="profile-dropdown-heading">
              <span class="profile-dropdown-avatar" aria-hidden="true"><svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor" stroke-width="2.4"><circle cx="12" cy="8" r="3.5"></circle><path d="M5 20c.4-3.5 2.7-5.5 7-5.5s6.6 2 7 5.5"></path></svg></span>
              <span class="profile-dropdown-identity"><strong>${escapeHtml(fullName)}</strong><span>${isAdmin ? 'Master Admin' : isDoctor ? 'Doctor' : 'Patient'}</span></span>
            </div>
            <a href="#" class="profile-dropdown-item" data-view-target="profile"><svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"></circle><path d="M4 21c.5-4.2 3.2-6.5 8-6.5s7.5 2.3 8 6.5"></path></svg><span>View Profile</span></a>
            ${isAdmin
              ? '<a href="#" class="profile-dropdown-item" data-view-target="settings"><svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-1.7 2.94-.08-.02a1.7 1.7 0 0 0-1.7.35l-.06.05h-3.4l-.04-.08a1.7 1.7 0 0 0-1.45-1.03l-.08-.01-1.7-2.94.05-.07a1.7 1.7 0 0 0-.18-1.76L9.4 14v-4l.06-.05a1.7 1.7 0 0 0 .18-1.76l-.05-.07 1.7-2.94.08-.01a1.7 1.7 0 0 0 1.45-1.03l.04-.08h3.4l.06.05a1.7 1.7 0 0 0 1.7.35l.08-.02 1.7 2.94-.06.06A1.7 1.7 0 0 0 19.4 9l.06.05v4z"></path></svg><span>Settings</span></a>'
              : '<a href="#" class="profile-dropdown-item" data-view-target="appointments"><svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M16 3v4M8 3v4M3 10h18M9 15l2 2 4-4"></path></svg><span>Appointments</span></a>'}
            <button type="button" class="profile-dropdown-item profile-dropdown-logout btn-logout-action" id="btn-logout-action"><svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M10 17l5-5-5-5M15 12H3"></path><path d="M12 3h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7"></path></svg><span>Log Out</span></button>
          </div>
        </div>
      </div>
    </header>
  `;

  // Re-fetch DOM elements now that they exist
  el.sidebarToggleBtn = document.getElementById('sidebar-toggle-btn');
  el.appSidebar = document.getElementById('app-sidebar');
  el.btnProfileToggle = document.getElementById('btn-profile-toggle');
  el.profileDropdownPanel = document.getElementById('profile-dropdown-panel');
  el.btnNotifToggle = document.getElementById('btn-notif-toggle');
  el.notifDropdownPanel = document.getElementById('notif-dropdown-panel');
  el.notifCountBadge = document.getElementById('notif-count-badge');
  el.notifListContainer = document.getElementById('notif-list-container');
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
  updateSidebarAccessibility();
  document.getElementById('sidebar-toggle-btn')?.setAttribute('aria-expanded', 'false');
  window.scrollTo({ top: 0, behavior: 'smooth' });
  const pageHeading = targetView.querySelector('h1');
  if (pageHeading) {
    pageHeading.setAttribute('tabindex', '-1');
    pageHeading.focus({ preventScroll: true });
  }
  switch (targetViewId) {
    case 'patient-home':
    case 'patient-services': await renderServices(); break;
    case 'patient-book': await renderServices(); await populateBookingDentist(); break;
    case 'patient-appointments': await renderPatientAppointments(); break;
    case 'patient-records': await renderPatientRecords(); break;
    case 'patient-dentists': await renderDentists(); break;
    case 'admin-dashboard': await renderDashboardIfRoleAdminOrDoctor(); await renderAdminAppointments(); break;
    case 'admin-appointments': await renderAdminAppointments(); break;
    case 'admin-archives': await renderArchives(); break;
    case 'admin-patients': await renderAdminPatients(); break;
    case 'admin-records': await renderAdminRecords(); break;
    case 'admin-services': await renderAdminServices(); break;
    case 'admin-dentists': await renderAdminDentists(); break;
    case 'admin-settings': await renderAdminSettings(); break;
  }
}

async function renderServices() {
  const res = await api.get('services/list.php');
  if (!res.success) { showToast('Failed to load services.', 'error'); return; }
  serviceCatalog = res.data || [];
  renderServiceTables();
  if (el.bookServiceSelect) {
    el.bookServiceSelect.innerHTML = '<option value="">-- Choose a Service --</option>' +
      serviceCatalog.map((s) => '<option value="' + Number(s.id) + '">' + escapeHtml(s.service_name) + '</option>').join('');
  }
}

function renderServiceTables() {
  const formatServicePrice = (price) => {
    const amount = Number(price);
    return Number.isFinite(amount)
      ? '₱' + amount.toLocaleString('en-PH', { minimumFractionDigits: 0, maximumFractionDigits: 2 })
      : '₱0';
  };
  const renderRows = (tbody, query, admin = false) => {
    if (!tbody) return;
    const normalizedQuery = (query || '').trim().toLowerCase();
    const services = serviceCatalog.filter((service) =>
      !normalizedQuery || [service.service_name, service.description].some((value) => String(value || '').toLowerCase().includes(normalizedQuery))
    );
    const colspan = admin ? 4 : 3;
    if (!services.length) {
      tbody.innerHTML = '<tr><td colspan="' + colspan + '" class="empty-cell">' + (normalizedQuery ? 'No matching services found.' : 'No services are currently available.') + '</td></tr>';
      return;
    }
    tbody.innerHTML = services.map((service) => {
      const actions = admin
        ? '<td class="service-actions"><button type="button" class="service-edit-button" data-service-edit="' + Number(service.id) + '">Edit</button>' +
          '<button type="button" class="btn btn-sm btn-danger" data-service-delete="' + Number(service.id) + '">Delete</button></td>'
        : '';
      return '<tr>' +
        '<td><strong>' + escapeHtml(service.service_name) + '</strong></td>' +
        '<td class="service-description">' + escapeHtml(service.description || '') + '</td>' +
        '<td class="service-price-cell"><span class="service-price-value">' + formatServicePrice(service.price || 0) + '</span></td>' +
        actions +
        '</tr>';
    }).join('');
  };
  renderRows(el.patientServicesTbody, el.patientServiceSearch?.value, false);
  renderRows(el.adminServicesTbody, el.adminServiceSearch?.value, true);
}

async function renderDentists() {
  const res = await api.get('dentists/list.php');
  if (!res.success) { showToast('Failed to load dentists.', 'error'); return; }
  const dentists = res.data || [];
  if (el.bookDentistField) {
    el.bookDentistField.innerHTML = '<option value="">' + (dentists.length ? '-- Choose a Dentist --' : 'No dentists are available') + '</option>' +
      dentists.map((dentist) => '<option value="' + Number(dentist.id) + '">' + escapeHtml(dentist.full_name) + (dentist.specialization ? ' (' + escapeHtml(dentist.specialization) + ')' : '') + '</option>').join('');
    el.bookDentistField.disabled = dentists.length === 0;
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
        } else if (a.status === 'Approved') {
          actions = '<button class="btn btn-sm btn-primary" data-id="' + a.id + '" data-action="complete">Complete</button>' +
                    '<button class="btn btn-sm btn-outline" data-id="' + a.id + '" data-action="cancel">Cancel</button>';
        } else {
          actions = '';
        }
        if (role === 'super-admin') actions += '<button class="btn btn-sm btn-outline" data-id="' + a.id + '" data-action="archive">Delete</button>';
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
    const dentistId = Number(document.getElementById('book-dentist').value);
    const date = document.getElementById('book-date').value;
    const time = document.getElementById('book-time').value;
    const notes = document.getElementById('book-notes').value;
    if (!serviceId || !dentistId || !date || !time) { showToast('Please choose a service, dentist, date, and time slot.', 'error'); return; }
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
  const confirmed = await confirmModal({ title: 'Archive Service', message: 'Move this service to the archive? You can restore it later.', danger: true });
  if (!confirmed) return;
  const res = await api.del('users/delete.php', { id, table: 'services' });
  if (res.success) { showToast('Service moved to archive.'); await renderServices(); }
  else { showToast(res.message || 'Failed to archive service.', 'error'); }
}

async function openServiceForm(service = null) {
  const isEdit = Boolean(service);
  const resultPromise = openModal(isEdit ? 'Edit Service' : 'Add New Service',
    '<form id="form-service-editor">' +
    '<div class="form-group"><label for="m-service-name">Service Name</label><input id="m-service-name" type="text" maxlength="100" required></div>' +
    '<div class="form-group"><label for="m-service-description">Description</label><textarea id="m-service-description" rows="3" required></textarea></div>' +
    '<div class="form-group"><label for="m-service-price">Price (₱)</label><input id="m-service-price" type="number" min="0" step="0.01" required></div>' +
    '</form>', [
      { text: 'Cancel', class: 'btn-outline', value: false },
      { text: 'Confirm', class: 'btn-primary', value: () => ({
        service_name: document.getElementById('m-service-name').value.trim(),
        description: document.getElementById('m-service-description').value.trim(),
        price: document.getElementById('m-service-price').value,
      }) },
    ]);
  document.getElementById('m-service-name').value = service?.service_name || '';
  document.getElementById('m-service-description').value = service?.description || '';
  document.getElementById('m-service-price').value = service?.price ?? '';
  const values = await resultPromise;
  if (!values) return;
  if (!values.service_name || !values.description || values.price === '' || !Number.isFinite(Number(values.price)) || Number(values.price) < 0) {
    showToast('Enter a service name, description, and valid non-negative price.', 'error');
    return;
  }
  const payload = { ...values, price: Number(values.price) };
  const response = isEdit
    ? await api.patch('services/manage.php', { id: Number(service.id), ...payload })
    : await api.post('services/manage.php', payload);
  if (!response.success) {
    showToast(response.message || (isEdit ? 'Could not update service.' : 'Could not add service.'), 'error');
    return;
  }
  showToast(isEdit ? 'Service updated successfully.' : 'Service added successfully.');
  await renderServices();
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
      '<div class="form-group"><label for="m-rec-patient">Patient</label><select id="m-rec-patient" required>' + patientOptions + '</select></div>' +
      '<div class="form-group"><label for="m-rec-treatment">Treatment / Procedure</label><input type="text" id="m-rec-treatment" required placeholder="e.g. Tooth Extraction"></div>' +
      '<div class="form-group"><label for="m-rec-notes">Clinical Notes</label><textarea id="m-rec-notes" rows="3" required placeholder="Observations or prescribed care..."></textarea></div>' +
      '<div class="form-group"><label for="m-rec-date">Date</label><input type="date" id="m-rec-date" value="' + new Date().toISOString().split('T')[0] + '" required></div>' +
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
  if (notificationsRequestInFlight) return;
  notificationsRequestInFlight = true;
  try {
    const res = await api.get('notifications/list.php', { headers: { 'X-Background-Poll': '1' } });
    if (!res.success) return;
    const notifications = Array.isArray(res.data.notifications) ? res.data.notifications : [];
    const unreadCount = Number(res.data.unread_count) || 0;
    const notifToggle = document.getElementById('btn-notif-toggle');
    if (el.notifCountBadge) {
      el.notifCountBadge.textContent = unreadCount;
      el.notifCountBadge.classList.toggle('hidden', unreadCount === 0);
    }
    if (notifToggle) notifToggle.setAttribute('aria-label', unreadCount ? 'Notifications, ' + unreadCount + ' unread' : 'Notifications');
    const markAllReadButton = document.getElementById('btn-mark-all-read');
    if (markAllReadButton) markAllReadButton.disabled = unreadCount === 0;

    if (knownNotificationIds === null) {
      knownNotificationIds = new Set(notifications.map((item) => String(item.id)));
    } else {
      const arriving = notifications.filter((item) => Number(item.is_read) === 0 && !knownNotificationIds.has(String(item.id)));
      if (arriving.length > 3) showToast('You have ' + arriving.length + ' new notifications.', 'info');
      else arriving.slice().reverse().forEach((item) => showToast('New notification: ' + item.message, 'info'));
      notifications.forEach((item) => knownNotificationIds.add(String(item.id)));
    }

    const notificationSnapshot = JSON.stringify(notifications.map((item) => [item.id, item.message, item.is_read, item.created_at]));
    if (el.notifListContainer && notificationSnapshot !== lastNotificationSnapshot) {
      const previousScrollTop = el.notifListContainer.scrollTop;
      if (notifications.length === 0) { el.notifListContainer.innerHTML = '<li class="empty-list-text">No new notifications.</li>'; }
      else { el.notifListContainer.innerHTML = notifications.map((n) => {
        const isUnread = Number(n.is_read) === 0;
        return '<li class="notification-item' + (isUnread ? ' is-unread' : '') + '">' +
          '<div class="notification-message-row"><div class="notification-message">' + escapeHtml(n.message) + '</div>' +
          (isUnread ? '<span class="notification-unread-indicator"><span class="notification-unread-dot" aria-hidden="true"></span><span class="sr-only">Unread notification</span></span>' : '') + '</div>' +
          '<span class="notification-date">' + escapeHtml(n.created_at || '') + '</span>' +
          '</li>';
      }).join(''); }
      el.notifListContainer.scrollTop = previousScrollTop;
      lastNotificationSnapshot = notificationSnapshot;
    }
  } finally {
    notificationsRequestInFlight = false;
  }
}

function startNotificationPolling() {
  const refreshWhenVisible = () => {
    if (document.visibilityState === 'visible') renderNotifications();
  };
  window.setInterval(refreshWhenVisible, 5000);
  document.addEventListener('visibilitychange', refreshWhenVisible);
  window.addEventListener('focus', refreshWhenVisible);
}

async function renderGlobalSearch(query) {
  const panel = document.getElementById('global-search-results');
  if (!panel) return;
  const term = query.trim();
  if (term.length < 2) {
    panel.classList.add('hidden');
    panel.innerHTML = '';
    document.getElementById('global-search-input')?.setAttribute('aria-expanded', 'false');
    return;
  }

  panel.innerHTML = '<div class="global-search-message">Searching…</div>';
  panel.classList.remove('hidden');
  document.getElementById('global-search-input')?.setAttribute('aria-expanded', 'true');
  const params = new URLSearchParams({ q: term });
  const res = await api.get('search/global.php?' + params.toString());
  if (document.getElementById('global-search-input')?.value.trim() !== term) return;
  if (!res.success) {
    panel.innerHTML = '<div class="global-search-message">' + escapeHtml(res.message || 'Search failed.') + '</div>';
    return;
  }

  const data = res.data || {};
  const isAdmin = role === 'super-admin' || role === 'doctor';
  const groups = [
    { title: 'Appointments', target: isAdmin ? 'admin-appointments' : 'patient-appointments', items: (data.appointments || []).map((item) => ({ label: [item.appointment_date, item.time_slot].filter(Boolean).join(' '), detail: [item.patient_name, item.service_name, item.status].filter(Boolean).join(' · ') })) },
    { title: 'Services', target: isAdmin ? 'admin-services' : 'patient-services', items: (data.services || []).map((item) => ({ label: item.service_name, detail: item.description || '' })) },
    { title: 'Patients', target: isAdmin ? 'admin-patients' : 'patient-profile', items: (data.patients || []).map((item) => ({ label: item.full_name, detail: item.email || '' })) },
    { title: 'Dentists', target: isAdmin ? 'admin-dentists' : 'patient-dentists', items: (data.dentists || []).map((item) => ({ label: item.full_name, detail: item.specialization || '' })) },
  ].filter((group) => group.items.length);

  if (!groups.length) {
    panel.innerHTML = '<div class="global-search-message">No matches found.</div>';
    return;
  }
  panel.innerHTML = groups.map((group) => '<section class="global-search-group"><h3>' + escapeHtml(group.title) + '</h3>' +
    group.items.map((item) => '<button type="button" class="global-search-result" data-search-view="' + group.target + '"><strong>' + escapeHtml(item.label || '') + '</strong>' +
      (item.detail ? '<span>' + escapeHtml(item.detail) + '</span>' : '') + '</button>').join('') + '</section>').join('');
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
async function renderArchives() {
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
      summary = [record.appointment_date, record.time_slot, 'Patient ID ' + (record.patient_id ?? ''), record.status].filter(Boolean).join(' · ');
    } else if (entry.original_table === 'users') {
      summary = [record.full_name, record.email].filter(Boolean).join(' · ');
    } else if (entry.original_table === 'services') {
      summary = record.service_name || '';
    } else {
      summary = record.full_name || record.treatment_procedure || ('Record ID ' + entry.record_id);
    }
    return '<tr>' +
      '<td>' + escapeHtml(entry.original_table.replaceAll('_', ' ')) + '</td>' +
      '<td>' + escapeHtml(summary) + '</td>' +
      '<td>' + escapeHtml(entry.deleted_at || '') + '</td>' +
      '<td><button type="button" class="btn btn-sm btn-primary" data-archive-restore="' + Number(entry.id) + '">Restore</button> ' +
      '<button type="button" class="btn btn-sm btn-danger" data-archive-purge="' + Number(entry.id) + '">Delete permanently</button></td>' +
      '</tr>';
  }).join('');
}
async function renderReport() {
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

    const rows = res.data?.data || [];
    let headers;
    let rowHtml;
    if (type === 'patients') {
      headers = ['ID', 'Patient', 'Email', 'Registered'];
      rowHtml = rows.map((row) => '<tr><td>' + escapeHtml(row.id) + '</td><td>' + escapeHtml(row.full_name) + '</td><td>' + escapeHtml(row.email) + '</td><td>' + escapeHtml(row.created_at) + '</td></tr>');
    } else if (type === 'services') {
      headers = ['Service', 'Appointments'];
      rowHtml = rows.map((row) => '<tr><td>' + escapeHtml(row.service_name) + '</td><td>' + escapeHtml(row.total) + '</td></tr>');
    } else if (type === 'dentists') {
      headers = ['Dentist', 'Appointments'];
      rowHtml = rows.map((row) => '<tr><td>' + escapeHtml(row.full_name) + '</td><td>' + escapeHtml(row.total) + '</td></tr>');
    } else {
      headers = ['Status', 'Appointments'];
      rowHtml = rows.map((row) => '<tr><td>' + escapeHtml(row.status) + '</td><td>' + escapeHtml(row.total) + '</td></tr>');
    }

    if (results) {
      results.innerHTML = '<h3 class="mb-3">' + escapeHtml(res.message) + '</h3>' +
        (rowHtml.length
          ? '<div class="table-scroll"><table class="data-table"><thead><tr>' + headers.map((header) => '<th>' + header + '</th>').join('') + '</tr></thead><tbody>' + rowHtml.join('') + '</tbody></table></div>'
          : '<p class="text-sub text-center">No records found for the selected dates.</p>');
    }
  } finally {
    if (button) button.disabled = false;
  }
}
async function renderAdminSettings() {
  const [clinicRes, scheduleRes] = await Promise.all([
    api.get('settings/clinic.php'),
    api.get('schedules/clinic.php'),
  ]);
  if (!clinicRes.success) showToast(clinicRes.message || 'Failed to load clinic details.', 'error');
  else {
    const phone = document.getElementById('admin-setting-phone');
    const address = document.getElementById('admin-setting-address');
    if (phone) phone.value = clinicRes.data.phone_number || '';
    if (address) address.value = clinicRes.data.address || '';
  }
  if (!scheduleRes.success) showToast(scheduleRes.message || 'Failed to load clinic hours.', 'error');
  else {
    const days = document.getElementById('sched-days');
    const hours = document.getElementById('sched-hours');
    if (days) days.value = scheduleRes.data.operating_days || '';
    if (hours) hours.value = scheduleRes.data.operating_hours || '';
  }
}
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

if (document.getElementById('admin-pass-change-form')) {
  const adminPasswordForm = document.getElementById('admin-pass-change-form');
  adminPasswordForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const currentPassword = document.getElementById('admin-curr-pass').value;
    const newPassword = document.getElementById('admin-new-pass').value;
    if (newPassword.length < 8) { showToast('New password must be at least 8 characters.', 'error'); return; }
    const confirmed = await confirmModal({ title: 'Change Password', message: 'Change your account password?' });
    if (!confirmed) return;
    const res = await api.post('auth/password.php', { current_password: currentPassword, new_password: newPassword });
    if (res.success) {
      showToast('Password changed.');
      adminPasswordForm.reset();
    } else {
      showToast(res.message || 'Password change failed.', 'error');
    }
  });
}

function bindEvents() {
  // The navigation is injected after this module loads, so resolve its controls now.
  const sidebarToggleBtn = document.getElementById('sidebar-toggle-btn');
  const appSidebar = document.getElementById('app-sidebar');
  const btnProfileToggle = document.getElementById('btn-profile-toggle');
  const profileDropdownPanel = document.getElementById('profile-dropdown-panel');
  const btnNotifToggle = document.getElementById('btn-notif-toggle');
  const notifDropdownPanel = document.getElementById('notif-dropdown-panel');
  const markAllReadButton = document.getElementById('btn-mark-all-read');
  const searchInput = document.getElementById('global-search-input');
  const searchResults = document.getElementById('global-search-results');
  let searchTimer;
  const scheduleForm = document.getElementById('schedule-settings-form');
  const clinicInfoForm = document.getElementById('admin-clinic-info-form');
  const addServiceButton = document.getElementById('btn-modal-service');
  if (addServiceButton) addServiceButton.addEventListener('click', () => openServiceForm());
  if (el.patientServiceSearch) el.patientServiceSearch.addEventListener('input', renderServiceTables);
  if (el.adminServiceSearch) el.adminServiceSearch.addEventListener('input', renderServiceTables);
  if (el.adminServicesTbody) el.adminServicesTbody.addEventListener('click', async (event) => {
    const editButton = event.target.closest('[data-service-edit]');
    const deleteButton = event.target.closest('[data-service-delete]');
    if (editButton) {
      const service = serviceCatalog.find((item) => Number(item.id) === Number(editButton.dataset.serviceEdit));
      if (service) await openServiceForm(service);
    } else if (deleteButton) {
      await window.deleteService(Number(deleteButton.dataset.serviceDelete));
    }
  });
  const runReportButton = document.getElementById('btn-run-report');
  if (runReportButton) runReportButton.addEventListener('click', renderReport);
  if (scheduleForm) scheduleForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const operating_days = document.getElementById('sched-days').value.trim();
    const operating_hours = document.getElementById('sched-hours').value.trim();
    const res = await api.post('schedules/clinic.php', { operating_days, operating_hours });
    if (res.success) showToast('Operating hours saved.');
    else showToast(res.message || 'Failed to save operating hours.', 'error');
  });
  if (clinicInfoForm) clinicInfoForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const phone_number = document.getElementById('admin-setting-phone').value.trim();
    const address = document.getElementById('admin-setting-address').value.trim();
    const res = await api.post('settings/clinic.php', { phone_number, address });
    if (res.success) showToast('Clinic details saved.');
    else showToast(res.message || 'Failed to save clinic details.', 'error');
  });
  if (sidebarToggleBtn && appSidebar) sidebarToggleBtn.addEventListener('click', () => {
    const willOpen = !appSidebar.classList.contains('show-sidebar');
    appSidebar.classList.toggle('show-sidebar', willOpen);
    sidebarToggleBtn.setAttribute('aria-expanded', String(willOpen));
    updateSidebarAccessibility();
  });
  window.addEventListener('resize', updateSidebarAccessibility);
  document.querySelectorAll('.nav-link').forEach((link) => {
    link.addEventListener('click', (e) => { e.preventDefault(); switchView(link.getAttribute('data-view')); });
  });
  document.querySelectorAll('[data-navigate]').forEach((button) => {
    button.addEventListener('click', () => switchView(button.getAttribute('data-navigate')));
  });
  document.querySelectorAll('.btn-logout-action').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const confirmed = await confirmModal({ title: 'Log Out', message: 'Are you sure you want to log out?', danger: true });
      if (!confirmed) return;
      await api.post('auth/logout.php');
      window.location.assign(new URL('./index.html', document.baseURI).href);
    });
  });
  if (btnProfileToggle) btnProfileToggle.addEventListener('click', (e) => {
    e.stopPropagation();
    const willOpen = profileDropdownPanel.classList.contains('hidden');
    profileDropdownPanel.classList.toggle('hidden');
    profileDropdownPanel.setAttribute('aria-hidden', String(!willOpen));
    btnProfileToggle.setAttribute('aria-expanded', String(willOpen));
    notifDropdownPanel.classList.add('hidden');
    notifDropdownPanel.setAttribute('aria-hidden', 'true');
    btnNotifToggle.setAttribute('aria-expanded', 'false');
  });
  if (btnNotifToggle) btnNotifToggle.addEventListener('click', (e) => {
    e.stopPropagation();
    const willOpen = notifDropdownPanel.classList.contains('hidden');
    notifDropdownPanel.classList.toggle('hidden', !willOpen);
    notifDropdownPanel.setAttribute('aria-hidden', String(!willOpen));
    btnNotifToggle.setAttribute('aria-expanded', String(willOpen));
    profileDropdownPanel.classList.add('hidden');
    profileDropdownPanel.setAttribute('aria-hidden', 'true');
    btnProfileToggle.setAttribute('aria-expanded', 'false');
  });
  if (searchInput) searchInput.addEventListener('input', () => {
    clearTimeout(searchTimer);
    const query = searchInput.value;
    if (query.trim().length < 2) {
      if (searchResults) { searchResults.classList.add('hidden'); searchResults.innerHTML = ''; }
      searchInput.setAttribute('aria-expanded', 'false');
      return;
    }
    searchTimer = setTimeout(() => renderGlobalSearch(query), 250);
  });
  if (searchInput) searchInput.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && searchResults) {
      searchResults.classList.add('hidden');
      searchInput.setAttribute('aria-expanded', 'false');
    }
    if (event.key === 'ArrowDown' && searchResults && !searchResults.classList.contains('hidden')) {
      const firstResult = searchResults.querySelector('[data-search-view]');
      if (firstResult) { event.preventDefault(); firstResult.focus(); }
    }
    if (event.key === 'Enter' && searchResults && !searchResults.classList.contains('hidden')) {
      const firstResult = searchResults.querySelector('[data-search-view]');
      if (firstResult) firstResult.click();
    }
  });
  if (searchResults) searchResults.addEventListener('keydown', (event) => {
    const results = Array.from(searchResults.querySelectorAll('[data-search-view]'));
    const currentIndex = results.indexOf(document.activeElement);
    if (event.key === 'Escape') {
      event.preventDefault();
      searchResults.classList.add('hidden');
      searchInput?.setAttribute('aria-expanded', 'false');
      searchInput?.focus();
    } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      if (!results.length) return;
      event.preventDefault();
      const direction = event.key === 'ArrowDown' ? 1 : -1;
      const nextIndex = (currentIndex + direction + results.length) % results.length;
      results[nextIndex].focus();
    }
  });
  if (searchResults) searchResults.addEventListener('click', (event) => {
    const result = event.target.closest('[data-search-view]');
    if (!result) return;
    event.stopPropagation();
    const target = result.getAttribute('data-search-view');
    if (searchInput) searchInput.value = '';
    searchResults.classList.add('hidden');
    searchInput?.setAttribute('aria-expanded', 'false');
    switchView(target);
  });
  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    const profileIsOpen = profileDropdownPanel && !profileDropdownPanel.classList.contains('hidden');
    const notificationsAreOpen = notifDropdownPanel && !notifDropdownPanel.classList.contains('hidden');
    const sidebarIsOpen = appSidebar && appSidebar.classList.contains('show-sidebar');
    if (!profileIsOpen && !notificationsAreOpen && !sidebarIsOpen) return;
    closeDropdowns();
    if (sidebarIsOpen) {
      appSidebar.classList.remove('show-sidebar');
      sidebarToggleBtn?.setAttribute('aria-expanded', 'false');
      updateSidebarAccessibility();
    }
    (profileIsOpen ? btnProfileToggle : notificationsAreOpen ? btnNotifToggle : sidebarToggleBtn)?.focus();
  });
  if (markAllReadButton) markAllReadButton.addEventListener('click', async (event) => {
    event.stopPropagation();
    markAllReadButton.disabled = true;
    let markedRead = false;
    try {
      const res = await api.post('notifications/read.php', { mark_all: true });
      if (!res.success) {
        showToast(res.message || 'Could not mark notifications as read.', 'error');
        return;
      }
      markedRead = true;
      await renderNotifications();
      showToast('All notifications marked as read.');
    } finally {
      if (!markedRead) markAllReadButton.disabled = false;
    }
  });
  document.addEventListener('click', closeDropdowns);
  document.querySelectorAll('[data-view-target]').forEach((item) => {
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
      if (action === 'archive') {
        const confirmed = await confirmModal({ title: 'Move Appointment to Archive', message: 'This appointment will be moved to the archive. You can restore it or permanently delete it later.', danger: true });
        if (!confirmed) return;
        const res = await api.del('users/delete.php', { id: Number(id), table: 'appointments' });
        if (!res.success) { showToast(res.message || 'Could not archive appointment.', 'error'); return; }
        showToast('Appointment moved to archive.');
        await renderAdminAppointments();
        await renderDashboardIfRoleAdminOrDoctor();
        return;
      }
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
  const archiveTbody = document.getElementById('admin-archives-tbody');
  if (archiveTbody) archiveTbody.addEventListener('click', async (event) => {
    const restoreButton = event.target.closest('button[data-archive-restore]');
    const purgeButton = event.target.closest('button[data-archive-purge]');
    if (restoreButton) {
      const id = Number(restoreButton.getAttribute('data-archive-restore'));
      const confirmed = await confirmModal({ title: 'Restore Archived Record', message: 'Restore this record from the archive?' });
      if (!confirmed) return;
      const res = await api.post('archives/restore.php', { id });
      if (!res.success) { showToast(res.message || 'Could not restore this record.', 'error'); return; }
      showToast('Record restored.');
      await renderArchives();
      await renderAdminAppointments();
    } else if (purgeButton) {
      const id = Number(purgeButton.getAttribute('data-archive-purge'));
      const confirmed = await confirmModal({ title: 'Delete Permanently', message: 'This permanently deletes the archived record and cannot be undone. Continue?', danger: true });
      if (!confirmed) return;
      const res = await api.post('archives/hard-delete.php', { id, confirm: 'DELETE' });
      if (!res.success) { showToast(res.message || 'Could not permanently delete this record.', 'error'); return; }
      showToast('Archived record permanently deleted.');
      await renderArchives();
    }
  });
}
function closeDropdowns() {
  if (el.profileDropdownPanel) el.profileDropdownPanel.classList.add('hidden');
  if (el.notifDropdownPanel) el.notifDropdownPanel.classList.add('hidden');
  document.getElementById('profile-dropdown-panel')?.setAttribute('aria-hidden', 'true');
  document.getElementById('notif-dropdown-panel')?.setAttribute('aria-hidden', 'true');
  document.getElementById('btn-profile-toggle')?.setAttribute('aria-expanded', 'false');
  document.getElementById('btn-notif-toggle')?.setAttribute('aria-expanded', 'false');
  document.getElementById('global-search-results')?.classList.add('hidden');
  document.getElementById('global-search-input')?.setAttribute('aria-expanded', 'false');
}
function updateSidebarAccessibility() {
  const sidebar = document.getElementById('app-sidebar');
  const toggle = document.getElementById('sidebar-toggle-btn');
  if (!sidebar) return;
  const isMobile = window.matchMedia('(max-width: 768px)').matches;
  const isOpen = sidebar.classList.contains('show-sidebar');
  const hiddenFromAccessTree = isMobile && !isOpen;
  if (hiddenFromAccessTree && sidebar.contains(document.activeElement)) toggle?.focus();
  sidebar.inert = hiddenFromAccessTree;
  sidebar.setAttribute('aria-hidden', String(hiddenFromAccessTree));
  if (toggle) toggle.setAttribute('aria-expanded', String(isOpen));
}
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
