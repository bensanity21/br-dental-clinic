// scripts/reset-password.js - set a new password from a reset link.
import { bindPasswordToggles, clearFieldErrors, setAuthAlert, showFieldError } from "./helpers.js";
import { api } from "./api.js";

bindPasswordToggles();

const form = document.getElementById("reset-form");
const token = new URLSearchParams(window.location.hash.slice(1)).get("token")
  || new URLSearchParams(window.location.search).get("token")
  || "";

// Keep the token in memory for the POST, but remove it from the address bar
// and browser history as soon as the page has captured it.
if (token && window.history && window.history.replaceState) {
  window.history.replaceState(null, "", window.location.pathname);
}

// A missing token means the link was truncated or hand-edited.
if (!token) {
  setAuthAlert(
    "This page needs a reset link. Request a new one from the sign-in page.",
    "danger"
  );
  if (form) form.querySelector("button[type=submit]").disabled = true;
}

if (form && token) {
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    clearFieldErrors(form);

    const password = document.getElementById("reset-password").value;
    const confirm = document.getElementById("reset-confirm").value;

    let valid = true;
    if (password.length < 12) {
      showFieldError("reset-password", "Use at least 12 characters.");
      valid = false;
    }
    if (password !== confirm) {
      showFieldError("reset-confirm", "The passwords do not match.");
      valid = false;
    }
    if (!valid) return;

    const submitBtn = form.querySelector("button[type=submit]");
    submitBtn.disabled = true;
    setAuthAlert("Saving your new password...", "info");

    try {
      const res = await api.post("auth/password-reset-confirm.php", {
        token,
        new_password: password,
        confirm_password: confirm,
      });

      if (res && res.success) {
        setAuthAlert(res.message, "success");
        form.classList.add("hidden");
        // Give the user a moment to read the confirmation before the redirect.
        window.setTimeout(() => {
          window.location.replace(new URL("./login.html", document.baseURI).href);
        }, 2200);
      } else {
        setAuthAlert(
          res && res.message ? res.message : "This reset link is no longer valid.",
          "danger"
        );
        submitBtn.disabled = false;
      }
    } catch (err) {
      console.error("Reset error:", err);
      setAuthAlert("Failed to reach the server. Please try again.", "danger");
      submitBtn.disabled = false;
    }
  });
}
