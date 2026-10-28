// scripts/modal.js - Reusable confirmation modal (replaces browser confirm())
// Usage: confirmModal({ title, message, danger: false, onConfirm, onCancel })

const modalBackdrop = document.getElementById('modal-backdrop');
const modalTitle = document.getElementById('modal-title');
const modalBody = document.getElementById('modal-body');
const modalFooter = document.getElementById('modal-footer');

let currentResolve = null;

export function openModal(title, bodyHTML, actions = []) {
  return new Promise((resolve) => {
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
        closeModal();
        resolve(result);
      });
      modalFooter.appendChild(btn);
    });

    modalBackdrop.classList.remove('hidden');
    modalBackdrop.setAttribute('aria-hidden', 'false');
  });
}

export function closeModal() {
  modalBackdrop.classList.add('hidden');
  modalBackdrop.setAttribute('aria-hidden', 'true');
  currentResolve = null;
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
  modalBackdrop.addEventListener('click', (e) => {
    if (e.target === modalBackdrop) closeModal();
  });
}

// Close on Escape key
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') closeModal();
});
