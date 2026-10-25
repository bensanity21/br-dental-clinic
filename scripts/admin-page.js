import { requireRole } from "./auth.js";
import { renderPortalLayout } from "./shared-layout.js";
import { state } from "./state.js";
import { initPortal } from "./portal.js";

if (requireRole("super-admin")) {
  renderPortalLayout(state.session.role, state.session.user);
  initPortal("super-admin");
}
