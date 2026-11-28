// scripts/site-services.js - Full list of active services with prices.
import {
  esc, peso, duration, loadClinicInfo, loadServices, serviceImage,
  initSiteNav, initBookingLinks, stampYear,
} from './site.js?v=2';

initSiteNav();
initBookingLinks();
stampYear();
loadClinicInfo();

const list = document.getElementById('services-list');
let servicePage = 1;
const PAGE_SIZE = 6;
let activeServices = [];

function pagination(page, pages) {
  if (pages <= 1) return '';
  const numbers = [...new Set([1, pages, page - 1, page, page + 1].filter((n) => n >= 1 && n <= pages))].sort((a, b) => a - b);
  let prior = 0;
  const buttons = numbers.map((n) => {
    const gap = n - prior > 1 ? '<span class="pagination-ellipsis" aria-hidden="true">…</span>' : '';
    prior = n;
    return gap + '<button type="button" class="pagination-page' + (n === page ? ' is-active' : '') + '" data-site-service-page="' + n + '"' + (n === page ? ' aria-current="page"' : '') + '>' + n + '</button>';
  }).join('');
  return '<nav class="pagination" aria-label="Service pages"><button type="button" class="pagination-page" data-site-service-page="' + Math.max(1, page - 1) + '" aria-label="Previous page"' + (page === 1 ? ' disabled' : '') + '>‹</button>' + buttons + '<button type="button" class="pagination-page" data-site-service-page="' + Math.min(pages, page + 1) + '" aria-label="Next page"' + (page === pages ? ' disabled' : '') + '>›</button></nav>';
}

function paintServices() {
  const pages = Math.max(1, Math.ceil(activeServices.length / PAGE_SIZE));
  servicePage = Math.min(servicePage, pages);
  const visible = activeServices.slice((servicePage - 1) * PAGE_SIZE, servicePage * PAGE_SIZE);
  list.innerHTML = visible.map((service) => {
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
  }).join('') + pagination(servicePage, pages);
}

async function renderServices() {
  if (!list) return;
  const services = await loadServices();

  if (!services.length) {
    list.innerHTML = '<p class="text-sub">No services are listed right now. Please call the clinic for details.</p>';
    return;
  }

  activeServices = services;
  paintServices();
}

list?.addEventListener('click', (event) => {
  const button = event.target.closest('[data-site-service-page]');
  if (!button) return;
  servicePage = Number(button.dataset.siteServicePage) || 1;
  paintServices();
  list.scrollIntoView({ behavior: 'smooth', block: 'start' });
});

renderServices();
