export function validateEmail(email) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

export function bindPasswordToggles() {
  document.addEventListener("click", event => {
    if (!event.target.matches(".btn-toggle-pass")) return;

    const input = document.getElementById(event.target.getAttribute("data-target"));
    if (!input) return;

    const showingPassword = input.type === "password";
    input.type = showingPassword ? "text" : "password";
    event.target.textContent = showingPassword ? "Hide" : "Show";
    event.target.setAttribute("aria-label", showingPassword ? "Hide password" : "Show password");
  });
}

export function clearFieldErrors(form) {
  form.querySelectorAll(".field-error").forEach(error => {
    error.textContent = "";
  });
}

export function showFieldError(id, message) {
  const error = document.getElementById(`${id}-error`);
  if (error) error.textContent = message;
}

export function setAuthAlert(message, type = "danger") {
  const alert = document.getElementById("auth-alert");
  alert.className = `alert alert-${type}`;
  alert.textContent = message;
  alert.classList.remove("hidden");
}
