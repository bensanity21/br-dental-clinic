// scripts/login-page.js - Login page using the new API client (PHP sessions + cookies)
import { bindPasswordToggles, clearFieldErrors, setAuthAlert, showFieldError, validateEmail } from "./helpers.js";
import { api, redirectIfSignedIn } from "./api.js";
import { showToast } from "./toast.js";

bindPasswordToggles();

redirectIfSignedIn();
window.addEventListener('pageshow', (e) => { if (e.persisted) redirectIfSignedIn(); });

const form = document.getElementById("signin-form");
if (form) {
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
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
      const res = await api.post("login.php", { email, password });

      if (res && res.success) {
        showToast("Welcome back, " + res.data.name + "!");
        const redirectUrl = (res.data.role === "super-admin" || res.data.role === "doctor")
          ? new URL("./admin.html", document.baseURI).href
          : new URL("./user.html", document.baseURI).href;
        window.location.replace(redirectUrl);
      } else {
        setAuthAlert(res && res.message ? res.message : "Invalid email or password.", "danger");
      }
    } catch (err) {
      console.error("Login error:", err);
      setAuthAlert("Failed to connect to the backend server. " + (err.message || ""), "danger");
    }
  });
}

// Remember me, Google/Facebook sign-in and password recovery are not implemented;
// their buttons were removed from the sign-in page rather than left dead.

import { loadClinicInfo } from './clinic-info.js?v=1';
loadClinicInfo();
