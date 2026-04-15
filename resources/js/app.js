import './bootstrap';

//import modules
import { initTracking } from "./modules/tracking";
import { initActiveJeeps } from "./modules/active-jeeps";
import { initAuthPage } from "./modules/login";
import { initDriverDashboard } from "./modules/driver-dashboard";

console.log('app.js loaded');

//Module init
document.addEventListener("DOMContentLoaded", () => {
    const app = document.getElementById("app");
    const page = app?.dataset.page;

    if (!window.Echo) {
        console.error("Echo not initialized");
        return;
    }

    console.log("Page:", page);

    switch (page) {
        case "tracking":
            initTracking();
            break;

        case "active-jeeps":
            initActiveJeeps();
            break;
        case "auth":
            initAuthPage();
            break;
        case "driver-dashboard":
            initDriverDashboard();
            break;
    }
});
