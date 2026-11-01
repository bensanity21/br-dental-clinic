// scripts/site-services.js - Full list of active services with prices.
import {
  esc, peso, duration, loadClinicInfo, loadServices, serviceImage,
  initSiteNav, stampYear,
} from './site.js?v=1';

initSiteNav();
stampYear();
loadClinicInfo();

const list = document.getElementById('services-list');

async function renderServices() {
  if (!list) return;
  const services = await loadServices();

  if (!services.length) {
    list.innerHTML = '<p class="text-sub">No services are listed right now. Please call the clinic for details.</p>';
    return;
  }

  list.innerHTML = services.map((service) => {
    const name = esc(service.service_name);
    return '<article class="site-card site-service-card">' +
      '<img src="' + esc(serviceImage(service.service_name)) + '" alt="" loading="lazy">' +
      '<div class="site-service-body">' +
        '<h3>' + name + '</h3>' +
        '<p>' + esc(service.description || 'Ask the clinic for more information about this treatment.') + '</p>' +
        '<div class="site-service-meta">' +
          '<span class="site-service-price">' + peso(service.price) + '</span>' +
          (service.duration_minutes ? '<span class="site-service-duration">' + esc(duration(service.duration_minutes)) + '</span>' : '') +
        '</div>' +
      '</div>' +
      '</article>';
  }).join('');
}

renderServices();
