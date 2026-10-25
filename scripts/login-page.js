import { authenticateSession, getAuthenticatedSession, redirectForRole } from "./auth.js";
import { bindPasswordToggles, clearFieldErrors, setAuthAlert, showFieldError, validateEmail } from "./helpers.js";

bindPasswordToggles();

const session = getAuthenticatedSession();
if (session) redirectForRole(session.role);

document.getElementById("signin-form").addEventListener("submit", async event => {
  event.preventDefault();
  const form = event.currentTarget;
  clearFieldErrors(form);

  const email = document.getElementById("signin-email").value.trim();
  const password = document.getElementById("signin-password").value.trim();

  if (!validateEmail(email)) {
    showFieldError("signin-email", "Enter a valid email address.");
    return;
  }
  if (!password) {
    showFieldError("signin-password", "Password is required.");
    return;
  }

  setAuthAlert("Authenticating with server...", "info");

    try {
        const response = await fetch('api/login.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, password })
        });

        const result = await response.json();

        if (result.status === 'success') {
            // authenticateSession saves the session and redirects to the role's page.
            authenticateSession(result.user.role, {
                name: result.user.name,
                email: result.user.email
            });
        } else {
            setAuthAlert(result.message, "danger");
        }
    } catch (err) {
        console.error('Login error:', err);
        setAuthAlert("Failed to connect to the backend server.", "danger");
    }
});

document.getElementById("btn-oauth-google").addEventListener("click", () => {
  setAuthAlert("Google Auth requires backend configuration.", "danger");
});
document.getElementById("btn-oauth-facebook").addEventListener("click", () => {
  setAuthAlert("Facebook Auth requires backend OAuth setup.", "danger");
});
document.getElementById("btn-forgot-password").addEventListener("click", () => {
  setAuthAlert("Password recovery emails require an SMTP backend.", "danger");
});
