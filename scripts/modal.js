// scripts/modal.js - Reusable confirmation modal (replaces browser confirm())
// Usage: confirmModal({ title, message, danger: false, onConfirm, onCancel })

const modalBackdrop = document.getElementById('modal-backdrop');
const modalTitle = document.getElementById('modal-title');
const modalBody = document.getElementById('modal-body');
const modalFooter = document.getElementById('modal-footer');

let currentResolve = null;
let previousFocus = null;
const modalDialog = modalBackdrop?.querySelector('.modal-dialog');

export function openModal(title, bodyHTML, actions = []) {
  return new Promise((resolve) => {
    if (!modalBackdrop || !modalTitle || !modalBody || !modalFooter || !modalDialog) {
      resolve(false);
      return;
    }
    previousFocus = document.activeElement;
    currentResolve = resolve;
    modalTitle.textContent = title;
    modalBody.innerHTML = bodyHTML;
    modalFooter.innerHTML = '';

    actions.forEach((act) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = `btn ${act.class || 'btn-outline'}`;
      if (act.danger) btn.className += ' btn-danger';
      btn.textContent = act.text;
      btn.addEventListener('click', () => {
        const result = typeof act.value === 'function' ? act.value() : act.value;
        closeModal(result);
      });
      modalFooter.appendChild(btn);
    });

    modalBackdrop.classList.remove('hidden');
    modalBackdrop.setAttribute('aria-hidden', 'false');
    requestAnimationFrame(() => {
      const firstField = modalBody.querySelector('input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [autofocus]');
      const firstAction = modalFooter.querySelector('button:not([disabled])');
      (firstField || firstAction || modalDialog).focus();
    });
  });
}

export function closeModal(result = false) {
  if (!modalBackdrop) return;
  modalBackdrop.classList.add('hidden');
  modalBackdrop.setAttribute('aria-hidden', 'true');
  const resolve = currentResolve;
  currentResolve = null;
  resolve?.(result);
  const focusTarget = previousFocus;
  previousFocus = null;
  if (focusTarget instanceof HTMLElement && focusTarget.isConnected) focusTarget.focus();
}

// Confirmation dialog: returns true if confirmed, false if cancelled
export function confirmModal({ title = 'Confirm action', message = 'Are you sure?', danger = false } = {}) {
  return openModal(title, `<p style="margin:0 0 1rem 0">${message}</p>`, [
    { text: 'Cancel', class: 'btn-outline', value: false },
    { text: 'Confirm', class: 'btn-primary', value: true, danger },
  ]);
}

// Close when clicking backdrop
if (modalBackdrop) {
  modalBackdrop.querySelector('.btn-modal-close')?.addEventListener('click', () => closeModal(false));
  modalBackdrop.addEventListener('click', (e) => {
    if (e.target === modalBackdrop) closeModal();
  });
}

// Keep keyboard focus inside the open dialog and return it to the opener when closed.
document.addEventListener('keydown', (e) => {
  if (!modalBackdrop || modalBackdrop.classList.contains('hidden')) return;
  if (e.key === 'Escape') {
    e.preventDefault();
    closeModal(false);
    return;
  }
  if (e.key !== 'Tab' || !modalDialog) return;
  const focusable = Array.from(modalDialog.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'))
    .filter((node) => node.offsetParent !== null);
  if (!focusable.length) {
    e.preventDefault();
    modalDialog.focus();
    return;
  }
  const first = focusable[0];
  const last = focusable[focusable.length - 1];
  if (e.shiftKey && (document.activeElement === first || !modalDialog.contains(document.activeElement))) {
    e.preventDefault();
    last.focus();
  } else if (!e.shiftKey && (document.activeElement === last || !modalDialog.contains(document.activeElement))) {
    e.preventDefault();
    first.focus();
  }
});
