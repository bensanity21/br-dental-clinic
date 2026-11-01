// scripts/site.js - Shared behaviour for the public website.
// Loads clinic details, services and dentists from the public API so the
// marketing pages never hardcode information that lives in the database.

import { api } from './api.js?v=8';

/** Escape a value before putting it in HTML. */
export function esc(value) {
  return String(value ?? '').replace(/[&<>"']/g, (ch) => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]
  ));
}

/** Price formatted as ₱1,500 */
export function peso(price) {
  const amount = Number(price);
  if (!Number.isFinite(amount)) return '₱0';
  return '₱' + amount.toLocaleString('en-PH', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
}

/** 45 -> "45 min", 90 -> "1 hr 30 min" */
export function duration(minutes) {
  const m = Number(minutes);
  if (!Number.isFinite(m) || m <= 0) return '';
  if (m < 60) return m + ' min';
  const hours = Math.floor(m / 60);
  const rest = m % 60;
  return rest === 0 ? hours + ' hr' : hours + ' hr ' + rest + ' min';
}

/** First letters of the first two name parts, for the dentist avatar. */
export function initials(name) {
  return String(name || '')
    .replace(/^Dr\.?\s+/i, '')
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join('');
}

/**
 * Fill every element tagged data-clinic="..." with live values from the API.
 * Falls back silently to whatever the HTML already contains.
 */
export async function loadClinicInfo() {
  const res = await api.get('settings/public.php');
  if (!res.success || !res.data) return null;
  const d = res.data;

  const setText = (selector, value) => {
    if (!value) return;
    document.querySelectorAll(selector).forEach((el) => { el.textContent = value; });
  };

  setText('[data-clinic="name"]', d.clinic_name);
  setText('[data-clinic="phone"]', d.phone_number);
  setText('[data-clinic="address"]', d.address);
  setText('[data-clinic="hours"]', d.operating_hours);
  setText('[data-clinic="hours-days"]', d.operating_days);

  document.querySelectorAll('[data-clinic="phone-link"]').forEach((el) => {
    el.textContent = d.phone_number || el.textContent;
    if (d.phone_number) el.setAttribute('href', 'tel:' + String(d.phone_number).replace(/[^0-9+]/g, ''));
  });
  document.querySelectorAll('[data-clinic="map-link"]').forEach((el) => {
    if (d.map_url) el.setAttribute('href', d.map_url);
  });
  // Keep the embedded map on the same address the clinic actually has.
  if (d.address) {
    document.querySelectorAll('[data-clinic="map-embed"]').forEach((el) => {
      el.setAttribute('src', 'https://www.google.com/maps?q=' + encodeURIComponent(d.address) + '&output=embed');
    });
  }
  // An address split over two lines: street then city.
  document.querySelectorAll('[data-clinic="address-block"]').forEach((el) => {
    const parts = String(d.address || '').split(',');
    const street = parts.slice(0, 2).join(',').trim();
    const rest = parts.slice(2).join(',').trim();
    if (street && rest) { el.innerHTML = esc(street) + '<br>' + esc(rest); }
    else if (d.address) { el.textContent = d.address; }
  });

  return d;
}

/** Illustration for a service, reusing the portal's artwork. */
export function serviceImage(name) {
  const n = String(name || '').toLowerCase();
  if (n.includes('clean') || n.includes('prophy')) return 'assets/services/cleaning.svg';
  if (n.includes('fluoride')) return 'assets/services/fluoride.svg';
  if (n.includes('fill')) return 'assets/services/filling.svg';
  if (n.includes('extract') || n.includes('molar')) return 'assets/services/extraction.svg';
  if (n.includes('denture')) return 'assets/services/dentures.svg';
  if (n.includes('crown')) return 'assets/services/crown.svg';
  if (n.includes('ortho') || n.includes('brace')) return 'assets/services/braces.svg';
  if (n.includes('whiten')) return 'assets/services/whitening.svg';
  if (n.includes('canal')) return 'assets/services/root-canal.svg';
  return 'assets/services/general-care.svg';
}

/** Fetch the active services. */
export async function loadServices() {
  const res = await api.get('services/list.php');
  return res.success ? (res.data || []) : [];
}

/** Fetch the dentist list. */
export async function loadDentists() {
  const res = await api.get('dentists/list.php');
  return res.success ? (res.data || []) : [];
}

/** Mobile navigation toggle, shared by every public page. */
export function initSiteNav() {
  const toggle = document.getElementById('site-nav-toggle');
  const links = document.getElementById('site-nav-links');
  if (!toggle || !links) return;

  toggle.addEventListener('click', () => {
    const open = links.classList.toggle('is-open');
    toggle.setAttribute('aria-expanded', String(open));
  });
  // Close the menu after picking a destination.
  links.addEventListener('click', (event) => {
    if (event.target.closest('a')) {
      links.classList.remove('is-open');
      toggle.setAttribute('aria-expanded', 'false');
    }
  });
  // Escape closes it, for keyboard users.
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && links.classList.contains('is-open')) {
      links.classList.remove('is-open');
      toggle.setAttribute('aria-expanded', 'false');
      toggle.focus();
    }
  });
}

/** Redirect signed-in visitors straight to their portal. */
export async function redirectIfSignedIn() {
  try {
    const res = await fetch(new URL('./api/auth/me.php', document.baseURI).href, {
      credentials: 'include',
      cache: 'no-store',
    });
    if (!res.ok) return;
    const body = await res.json();
    const role = body?.data?.user?.role;
    if (!role) return;
    const page = (role === 'super-admin' || role === 'doctor') ? './admin.html' : './user.html';
    window.location.replace(new URL(page, document.baseURI).href);
  } catch {
    // Not signed in, or the endpoint is unavailable: stay on the public site.
  }
}

/** Current year for the footer copyright. */
export function stampYear() {
  document.querySelectorAll('[data-year]').forEach((el) => {
    el.textContent = String(new Date().getFullYear());
  });
}
