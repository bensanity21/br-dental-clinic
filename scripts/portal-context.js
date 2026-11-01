// scripts/portal-context.js - shared helpers for the portal feature modules.
//
// The portal was one 2,500-line file. Splitting it is only safe if the extracted
// modules receive their dependencies explicitly instead of closing over
// variables that live in another file. This module is that seam:
//
//   import { ctx, setContext, escapeHtml } from './portal-context.js';
//
// Each feature module reads the shared bits it needs from `ctx` and exports an
// init function, which portal.js calls after the session loads.

import { api, setCsrfToken } from './api.js?v=8';
import { confirmModal, openModal, closeModal } from './modal.js?v=10';
import { showToast } from './toast.js';

/**
 * Shared, deliberately small context. Anything a feature module needs beyond
 * this is passed in as a function argument.
 */
export const ctx = {
  /** Current user object from auth/me.php, or null. */
  currentUser: null,
  /** 'patient' | 'doctor' | 'super-admin'. */
  role: '',
  /** The dentist row linked to a doctor account, or null. */
  dentistInfo: null,
  /** Cached active services. */
  serviceCatalog: [],
};

/** Update the shared context in one place. */
export function setContext(patch) {
  Object.assign(ctx, patch);
}

// Re-export the shared helpers so feature modules import from one place and
// cannot drift onto different versions of api.js or modal.js.
export { api, setCsrfToken, confirmModal, openModal, closeModal, showToast };

/**
 * Escape a value for safe interpolation into innerHTML.
 * Mirrors the implementation in portal.js so behaviour is identical.
 */
export function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text ?? '';
  return div.innerHTML;
}

/** Format a price in Philippine pesos, matching the rest of the portal. */
export function peso(price) {
  const value = Number(price);
  if (!Number.isFinite(value)) return '';
  return '\u20B1' + value.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/** Human-readable role label. */
export function roleLabel(role) {
  if (role === 'super-admin') return 'Super Admin';
  if (role === 'doctor') return 'Doctor';
  return 'Patient';
}
