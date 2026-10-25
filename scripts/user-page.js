import { requireRole } from "./auth.js";
import { renderPortalLayout } from "./shared-layout.js";
import { state } from "./state.js";
import { initPortal } from "./portal.js";

if (requireRole("patient")) {
  renderPortalLayout(state.session.role, state.session.user);
  initPortal("patient");
}
