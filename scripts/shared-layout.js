const patientNavigation = `
  <nav id="nav-patient" class="sidebar-nav">
    <ul>
      <li><a href="#" class="nav-link active" data-view="patient-home">Home</a></li>
      <li><a href="#" class="nav-link" data-view="patient-about">About Us</a></li>
      <li><a href="#" class="nav-link" data-view="patient-services">Services</a></li>
      <li><a href="#" class="nav-link" data-view="patient-dentists">Our Dentists</a></li>
      <li><a href="#" class="nav-link" data-view="patient-contact">Contact Us</a></li>
      <li><a href="#" class="nav-link" data-view="patient-book">Book Appointment</a></li>
    </ul>
  </nav>`;

const adminNavigation = `
  <nav id="nav-admin" class="sidebar-nav">
    <ul>
      <li><a href="#" class="nav-link active" data-view="admin-dashboard">Dashboard</a></li>
      <li><a href="#" class="nav-link" data-view="admin-appointments">Appointments</a></li>
      <li><a href="#" class="nav-link" data-view="admin-patients">Patients</a></li>
      <li><a href="#" class="nav-link" data-view="admin-dentists">Dentists</a></li>
      <li><a href="#" class="nav-link" data-view="admin-records">Dental Records</a></li>
      <li><a href="#" class="nav-link" data-view="admin-services">Services</a></li>
      <li><a href="#" class="nav-link" data-view="admin-schedules">Schedules</a></li>
      <li><a href="#" class="nav-link" data-view="admin-reports">Reports</a></li>
      <li><a href="#" class="nav-link" data-view="admin-users">Users &amp; Roles</a></li>
      <li><a href="#" class="nav-link" data-view="admin-settings">Settings</a></li>
    </ul>
  </nav>`;

const appSidebar = `
  <aside id="app-sidebar" class="sidebar" aria-label="Main Navigation">
    <div class="sidebar-header">
      <div class="clinic-brand">
        <img class="brand-icon" src="brand-icon.png" alt="" width="32" height="32">
        <div class="brand-meta">
          <span class="brand-name">BR Dental Clinic</span>
          <span id="portal-role-tag" class="role-tag"></span>
        </div>
      </div>
    </div>
    ${patientNavigation}
    ${adminNavigation}
    <div class="sidebar-footer">
      <button type="button" class="btn btn-outline btn-block btn-logout-action">Log Out</button>
    </div>
  </aside>`;

const appNavbar = `
  <header class="top-navbar">
    <button type="button" id="sidebar-toggle-btn" class="btn-icon" aria-label="Toggle navigation menu">
      <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
    <div class="navbar-search">
      <label for="global-search-input" class="sr-only">Search</label>
      <svg class="search-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4"></path></svg>
      <input type="search" id="global-search-input" placeholder="Search services, patients, or appointments...">
    </div>
    <div class="navbar-right">
      <div class="dropdown-wrap">
        <button type="button" class="btn-icon dropdown-trigger" id="btn-notif-toggle" aria-haspopup="true" aria-expanded="false" aria-label="Notifications">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
          <span id="notif-count-badge" class="badge-count hidden">0</span>
        </button>
        <div id="notif-dropdown-panel" class="dropdown-panel dropdown-right hidden">
          <div class="dropdown-header"><h3>Notifications</h3></div>
          <ul id="notif-list-container" class="dropdown-list">
            <li class="empty-list-text">No new notifications from server</li>
          </ul>
        </div>
      </div>
      <div class="dropdown-wrap">
        <button type="button" class="btn-profile dropdown-trigger" id="btn-profile-toggle" aria-haspopup="true" aria-expanded="false">
          <span id="user-avatar-circle" class="avatar-circle">P</span>
          <span id="user-display-name" class="profile-name">Signed In User</span>
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
        <div id="profile-dropdown-panel" class="dropdown-panel dropdown-right hidden">
          <ul class="dropdown-list">
            <li><a href="#" class="dropdown-item" data-view-target="profile">My Profile</a></li>
            <li><a href="#" class="dropdown-item" data-view-target="appointments">My Appointments</a></li>
            <li><a href="#" class="dropdown-item" data-view-target="records">Dental Records</a></li>
            <li><a href="#" class="dropdown-item" data-view-target="settings">Account Settings</a></li>
            <li class="dropdown-divider"></li>
            <li><button type="button" class="dropdown-logout-btn btn-logout-action">Log Out</button></li>
          </ul>
        </div>
      </div>
    </div>
  </header>`;

const modal = `
  <div id="modal-backdrop" class="modal-backdrop hidden" aria-hidden="true">
    <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="modal-title">
      <header class="modal-header">
        <h2 id="modal-title">Dialog Title</h2>
        <button type="button" class="btn-icon btn-modal-close" aria-label="Close dialog">&times;</button>
      </header>
      <div id="modal-body" class="modal-body"></div>
      <footer id="modal-footer" class="modal-footer"></footer>
    </div>
  </div>`;

export function renderPortalLayout(role, user) {
  const isAdmin = role === "super-admin";
  document.getElementById("sidebar-slot").innerHTML = appSidebar;
  document.getElementById("navbar-slot").innerHTML = appNavbar;
  document.getElementById("modal-slot").innerHTML = modal;
  document.getElementById("nav-patient").classList.toggle("hidden", isAdmin);
  document.getElementById("nav-admin").classList.toggle("hidden", !isAdmin);
  document.getElementById("portal-role-tag").textContent = isAdmin ? "Master Admin Portal" : "Patient Portal";
  document.getElementById("user-display-name").textContent = user?.name || (isAdmin ? "Master Admin" : "Patient Account");
  document.getElementById("user-avatar-circle").textContent = (user?.name || (isAdmin ? "M" : "P")).charAt(0).toUpperCase();
}
