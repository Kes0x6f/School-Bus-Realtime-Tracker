import './bootstrap';

import { initTracking }         from "./modules/tracking";
import { initActiveJeeps }      from "./modules/active-jeeps";
import { initAuthPage }         from "./modules/login";
import { initDriverDashboard }  from "./modules/driver-dashboard";
import { initAdminDashboard }   from "./modules/admin-dashboard";
import { initAnnouncements }    from "./modules/announcements";

document.addEventListener("DOMContentLoaded", () => {
    const app  = document.getElementById("app");
    const page = app?.dataset.page;

    if (!window.Echo) {
        console.error("Echo not initialized");
        return;
    }

    switch (page) {
        case "tracking":
            initTracking();
            // Only show announcements relevant to this vehicle's route
            initAnnouncements(app.dataset.route || null);
            break;

        case "active-jeeps":
            initActiveJeeps();
            // Show all active announcements — no route filter
            initAnnouncements(null);
            break;

        case "auth":
            initAuthPage();
            break;

        case "driver-dashboard":
            initDriverDashboard();
            break;

        case "admin-dashboard":
            initAdminDashboard();
            break;
    }
});