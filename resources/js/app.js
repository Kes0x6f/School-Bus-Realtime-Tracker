import './bootstrap';

import { initTracking }         from "./modules/tracking";
import { initActiveJeeps }      from "./modules/active-jeeps";
import { initAuthPage }         from "./modules/login";
import { initDriverDashboard }  from "./modules/driver-dashboard";
import { initAdminDashboard }   from "./modules/admin-dashboard";
import { initAnnouncements }    from "./modules/announcements";
import { initStudentPasswordModal } from "./modules/student";

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
            initAnnouncements(app.dataset.route || null);
            initStudentPasswordModal();
            break;

        case "active-jeeps":
            initActiveJeeps();
            initAnnouncements(null);
            initStudentPasswordModal();
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