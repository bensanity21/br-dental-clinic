// scripts/forgot-password.js - request a password reset link.
import { bindPasswordToggles, clearFieldErrors, setAuthAlert, showFieldError, validateEmail } from "./helpers.js";
import { api } from "./api.js";

bindPasswordToggles();

const form = document.getElementById("forgot-form");

if (form) {
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    clearFieldErrors(form);

    const email = document.getElementById("forgot-email").value.trim();

    if (!validateEmail(email)) {
      showFieldError("forgot-email", "Enter a valid email address.");
      return;
    }

    const submitBtn = form.querySelector("button[type=submit]");
    submitBtn.disabled = true;
    setAuthAlert("Sending reset link...", "info");

    try {
      const res = await api.post("auth/password-reset.php", { email });

      if (res && res.success) {
        // The API answers the same way for a registered and an unregistered
        // address, so this message never reveals whether the account exists.
        setAuthAlert(res.message, "success");

        const link = res.data && res.data.reset_link;
        if (link) {
          const box = document.getElementById("dev-reset-link");
          const anchor = document.getElementById("dev-reset-anchor");
          if (box && anchor) {
            anchor.href = link;
            box.classList.remove("hidden");
          }
        }
      } else {
        setAuthAlert(res && res.message ? res.message : "Could not send the reset link.", "danger");
      }
    } catch (err) {
      console.error("Reset request error:", err);
      setAuthAlert("Failed to reach the server. Please try again.", "danger");
    } finally {
      submitBtn.disabled = false;
    }
  });
}
