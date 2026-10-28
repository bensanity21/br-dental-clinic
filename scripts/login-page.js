// scripts/login-page.js - Login page using the new API client (PHP sessions + cookies)
import { bindPasswordToggles, clearFieldErrors, setAuthAlert, showFieldError, validateEmail } from "./helpers.js";
import { api } from "./api.js";
import { showToast } from "./toast.js";

bindPasswordToggles();

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

      console.log("Login API response:", res); // Debug logging

      if (res && res.success) {
        showToast("Welcome back, " + res.data.user.name + "!");
        const redirectUrl = (res.data.user.role === "super-admin" || res.data.user.role === "doctor")
          ? "/draft for clinic/admin.html"
          : "/draft for clinic/user.html";
        // Use both assign and href for maximum browser compatibility
        window.location.assign(redirectUrl);
        window.location.href = redirectUrl;
      } else {
        setAuthAlert(res && res.message ? res.message : "Invalid email or password.", "danger");
      }
    } catch (err) {
      console.error("Login error:", err);
      setAuthAlert("Failed to connect to the backend server. " + (err.message || ""), "danger");
    }
  });
}

document.getElementById("btn-oauth-google")?.addEventListener("click", () => {
  setAuthAlert("Google Auth requires backend configuration.", "danger");
});
document.getElementById("btn-oauth-facebook")?.addEventListener("click", () => {
  setAuthAlert("Facebook Auth requires backend OAuth setup.", "danger");
});
document.getElementById("btn-forgot-password")?.addEventListener("click", () => {
  setAuthAlert("Password recovery emails require an SMTP backend.", "danger");
});
