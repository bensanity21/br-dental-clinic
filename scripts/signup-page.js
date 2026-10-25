import { authenticateSession, getAuthenticatedSession, redirectForRole } from "./auth.js";
import { bindPasswordToggles, clearFieldErrors, showFieldError, validateEmail, setAuthAlert } from "./helpers.js";

bindPasswordToggles();

const session = getAuthenticatedSession();
if (session) redirectForRole(session.role);

document.getElementById("signup-form").addEventListener("submit", async event => {
    event.preventDefault();

    const form = event.currentTarget;
    clearFieldErrors(form);

    const fullname = document.getElementById("signup-fullname").value.trim();
    const email = document.getElementById("signup-email").value.trim();
    const password = document.getElementById("signup-password").value.trim();
    const confirmPassword = document.getElementById("signup-confirm-password").value.trim();

    let valid = true;
    if (!fullname) { showFieldError("signup-fullname", "Full name is required."); valid = false; }
    if (!validateEmail(email)) { showFieldError("signup-email", "Valid email address is required."); valid = false; }
    if (password.length < 8) { showFieldError("signup-password", "Minimum 8 characters required."); valid = false; }
    if (password !== confirmPassword) { showFieldError("signup-confirm-password", "Passwords do not match."); valid = false; }

    if (!valid) return;

    setAuthAlert("Creating your account...", "info");

    try {
        const response = await fetch("api/signup.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                fullName: fullname,
                email: email,
                password: password
            })
        });

        const result = await response.json();

        if (result.status === "success") {
            // Backend created the account; authenticateSession saves state and redirects to the user page.
            authenticateSession("patient", {
                name: fullname,
                email: email
            });
        } else {
            showFieldError("signup-email", result.message);
        }
    } catch (err) {
        console.error("Signup error:", err);
        showFieldError("signup-email", "Failed to connect to backend server.");
    }
});