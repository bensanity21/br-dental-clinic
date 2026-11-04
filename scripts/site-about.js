// scripts/site-about.js - About page: live clinic details only.
import { loadClinicInfo, initSiteNav, stampYear } from './site.js?v=2';

initSiteNav();
stampYear();
loadClinicInfo();
