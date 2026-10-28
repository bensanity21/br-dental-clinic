// scripts/signup-page.js - Registration using the new API client (forces patient role)
import { bindPasswordToggles, clearFieldErrors, setAuthAlert, showFieldError, validateEmail } from "./helpers.js";
import { api } from "./api.js";
import { showToast } from "./toast.js";

bindPasswordToggles();

const form = document.getElementById("signup-form");
if (form) {
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    clearFieldErrors(form);
    setAuthAlert("Creating your account...", "info");

    const fullname = document.getElementById("signup-fullname").value.trim();
    const email = document.getElementById("signup-email").value.trim();
    const password = document.getElementById("signup-password").value.trim();
    const confirmPassword = document.getElementById("signup-confirm-password").value.trim();

    let valid = true;
    if (!fullname) { showFieldError("signup-fullname", "Full name is required."); valid = false; }
    if (!validateEmail(email)) { showFieldError("signup-email", "Valid email address is required."); valid = false; }
    if (password.length < 8) { showFieldError("signup-password", "Minimum 8 characters required."); valid = false; }
    if (password !== confirmPassword) { showFieldError("signup-confirm-password", "Passwords do not match."); valid = false; }
    if (!valid) { setAuthAlert("Please fix the highlighted fields.", "danger"); return; }

    try {
      const res = await api.post("signup.php", { fullName: fullname, email, password });

      if (res.success) {
        showToast("Account created! You can now sign in.");
        form.reset();
        window.location.assign(new URL("./index.html", document.baseURI).href);
      } else {
        setAuthAlert(res.message || "Failed to create account.", "danger");
      }
    } catch (err) {
      console.error("Signup error:", err);
      setAuthAlert("Failed to connect to the backend server.", "danger");
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
