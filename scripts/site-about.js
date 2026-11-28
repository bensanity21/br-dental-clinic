// scripts/site-about.js - About page: live clinic details only.
import { loadClinicInfo, initSiteNav, initBookingLinks, stampYear } from './site.js?v=2';

initSiteNav();
initBookingLinks();
stampYear();
loadClinicInfo();
