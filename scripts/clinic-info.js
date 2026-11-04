// scripts/clinic-info.js - Fill clinic details from the database
// Any page can include this. Elements opt in with data-clinic="name".
// The HTML keeps readable fallback text, which is replaced once the
// public settings endpoint answers.

import { api } from './api.js?v=8';

function setText(el, value) {
  if (el && value) el.textContent = value;
}

export async function loadClinicInfo() {
  const res = await api.get('settings/public.php');
  if (!res.success || !res.data) return null;
  const d = res.data;

  // Full address in one line.
  document.querySelectorAll('[data-clinic="address"]').forEach((el) => setText(el, d.address));
  // Address split across two lines (street, then city).
  document.querySelectorAll('[data-clinic="address-block"]').forEach((el) => {
    const parts = String(d.address || '').split(',');
    const street = parts.slice(0, 2).join(',').trim();
    const rest = parts.slice(2).join(',').trim();
    if (street && rest) {
      el.replaceChildren(document.createTextNode(street), document.createElement('br'), document.createTextNode(rest));
    }
    else setText(el, d.address);
  });

  document.querySelectorAll('[data-clinic="name"]').forEach((el) => setText(el, d.clinic_name));

  document.querySelectorAll('[data-clinic="phone"]').forEach((el) => setText(el, d.phone_number));
  document.querySelectorAll('[data-clinic="phone-link"]').forEach((el) => {
    setText(el, d.phone_number);
    if (d.phone_number) el.setAttribute('href', 'tel:' + String(d.phone_number).replace(/[^0-9+]/g, ''));
  });

  // Short form used in the "Call the clinic" card.
  document.querySelectorAll('[data-clinic="hours"]').forEach((el) => setText(el, d.operating_hours));
  // Long form: "Monday to Saturday · 8:00 AM - 6:00 PM"
  const longHours = [d.operating_days, d.operating_hours].filter(Boolean).join(' · ');
  document.querySelectorAll('[data-clinic="hours-full"]').forEach((el) => setText(el, longHours));
  document.querySelectorAll('[data-clinic="hours-days"]').forEach((el) => setText(el, d.operating_days));

  document.querySelectorAll('[data-clinic="map-link"]').forEach((el) => {
    if (d.map_url) el.setAttribute('href', d.map_url);
  });

  return d;
}
