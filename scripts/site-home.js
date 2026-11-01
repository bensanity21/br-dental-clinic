// scripts/site-home.js - Landing page: live clinic details and a service preview.
import {
  esc, peso, duration, loadClinicInfo, loadServices, serviceImage,
  initSiteNav, stampYear,
} from './site.js?v=1';

initSiteNav();
stampYear();
loadClinicInfo();

const grid = document.getElementById('home-services');

async function renderPreview() {
  if (!grid) return;
  const services = await loadServices();
  if (!services.length) {
    grid.innerHTML = '<p class="text-sub">Service information is unavailable right now. Please call the clinic.</p>';
    return;
  }

  // Three treatments is enough for the landing page; the full list has its own page.
  const featured = services.slice(0, 3);
  grid.innerHTML = featured.map((service) => {
    const name = esc(service.service_name);
    return '<article class="site-card site-service-card">' +
      '<img src="' + esc(serviceImage(service.service_name)) + '" alt="" loading="lazy">' +
      '<div class="site-service-body">' +
        '<h3>' + name + '</h3>' +
        '<p>' + esc(service.description || '') + '</p>' +
        '<div class="site-service-meta">' +
          '<span class="site-service-price">' + peso(service.price) + '</span>' +
          (service.duration_minutes ? '<span class="site-service-duration">' + esc(duration(service.duration_minutes)) + '</span>' : '') +
        '</div>' +
      '</div>' +
      '</article>';
  }).join('');
}

renderPreview();
