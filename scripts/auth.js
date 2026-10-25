import { saveState, state } from "./state.js";

export function authenticateSession(role, user) {
  state.session = { isAuthenticated: true, role, user };
  saveState();
  redirectForRole(role);
}

export function redirectForRole(role) {
  if (role === "super-admin") {
    window.location.assign("admin.html");
  } else if (role === "patient") {
    window.location.assign("user.html");
  } else {
    window.location.replace("index.html");
  }
}

export function getAuthenticatedSession() {
  return state.session.isAuthenticated && ["patient", "super-admin"].includes(state.session.role)
    ? state.session
    : null;
}

export function requireRole(requiredRole) {
  const session = getAuthenticatedSession();
  if (!session) {
    window.location.replace("index.html");
    return false;
  }

  const isAdmin = session.role === "super-admin";
  const isUser = session.role === "patient";
  if (!isAdmin && !isUser) {
    window.location.replace("index.html");
    return false;
  }
  if ((requiredRole === "super-admin" && !isAdmin) || (requiredRole === "patient" && !isUser)) {
    redirectForRole(session.role);
    return false;
  }

  return true;
}

export function terminateSession() {
  state.session = { isAuthenticated: false, role: null, user: null };
  saveState();
  window.location.assign("index.html");
}
