// scripts/setup-page.js - first-time administrator setup.
//
// The page refuses to submit once the account already has a real password;
// the server enforces that too, this is only for the message.

import { bindPasswordToggles, clearFieldErrors, setAuthAlert, showFieldError } from './helpers.js';
import { api } from './api.js';

bindPasswordToggles();

const form = document.getElementById('setup-form');

/** Ask the server whether setup is still pending. */
async function loadState() {
  try {
    const res = await api.get('setup/first-admin.php');
    if (!res.success) {
      setAuthAlert(res.message || 'Could not read the setup state.', 'danger');
      return;
    }
    if (!res.data.needs_setup) {
      setAuthAlert(
        'Setup has already been completed on this installation. Sign in with your administrator account instead.',
        'success'
      );
      form.classList.add('hidden');
      return;
    }
    const emailField = document.getElementById('setup-email');
    if (emailField) emailField.value = res.data.email || '';
  } catch (err) {
    console.error('Setup state error:', err);
    setAuthAlert('Could not reach the server. Please try again.', 'danger');
  }
}

if (form) {
  loadState();

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearFieldErrors(form);

    const password = document.getElementById('setup-password').value;
    const confirm = document.getElementById('setup-confirm').value;

    let valid = true;
    if (password.length < 12) {
      showFieldError('setup-password', 'Use at least 12 characters.');
      valid = false;
    }
    if (password !== confirm) {
      showFieldError('setup-confirm', 'The passwords do not match.');
      valid = false;
    }
    if (!valid) return;

    const submitBtn = form.querySelector('button[type=submit]');
    submitBtn.disabled = true;
    setAuthAlert('Saving the administrator password...', 'info');

    try {
      const res = await api.post('setup/first-admin.php', {
        password,
        confirm_password: confirm,
      });

      if (res && res.success) {
        setAuthAlert(res.message, 'success');
        form.classList.add('hidden');
        window.setTimeout(() => {
          window.location.replace(new URL('./login.html', document.baseURI).href);
        }, 2000);
      } else {
        setAuthAlert(res && res.message ? res.message : 'Setup could not be completed.', 'danger');
        submitBtn.disabled = false;
      }
    } catch (err) {
      console.error('Setup error:', err);
      setAuthAlert('Could not reach the server. Please try again.', 'danger');
      submitBtn.disabled = false;
    }
  });
}
