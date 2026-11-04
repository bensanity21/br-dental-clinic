// scripts/site-dentists.js - Public list of dentists from the database.
import {
  esc, initials, loadClinicInfo, loadDentists, initSiteNav, stampYear,
} from './site.js?v=2';

initSiteNav();
stampYear();
loadClinicInfo();

const list = document.getElementById('dentists-list');

async function renderDentists() {
  if (!list) return;
  const dentists = await loadDentists();

  if (!dentists.length) {
    list.innerHTML = '<p class="text-sub">Dentist information is unavailable right now. Please call the clinic.</p>';
    return;
  }

  list.innerHTML = dentists.map((dentist) => {
    const name = esc(dentist.full_name);
    return '<article class="site-card site-dentist-card">' +
      '<div class="site-dentist-avatar" aria-hidden="true">' + esc(initials(dentist.full_name)) + '</div>' +
      '<div>' +
        '<h3>' + name + '</h3>' +
        '<p class="site-dentist-role">' + esc(dentist.specialization || 'General Dentistry') + '</p>' +
        '<p>Sees patients at <span data-clinic="name">BR Dental Clinic</span> during the clinic\'s opening hours. ' +
          'Choose this dentist when you book and you will only be offered the days they work.</p>' +
      '</div>' +
      '</article>';
  }).join('');
}

renderDentists();
