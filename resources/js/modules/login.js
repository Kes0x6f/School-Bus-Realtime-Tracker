export function initAuthPage() {
    const app = document.getElementById("app");
    if (!app) return;

    const sections = ["landing", "studentLogin", "driverLogin", "adminLogin"];

    function show(targetId) {
        sections.forEach(id => {
            const el = document.getElementById(id);
            if (el) el.style.display = (id === targetId) ? "block" : "none";
        });
    }

    document.getElementById("toStudent")?.addEventListener("click", () => show("studentLogin"));
    document.getElementById("toDriver")?.addEventListener("click",  () => show("driverLogin"));
    document.getElementById("toAdmin")?.addEventListener("click",   () => show("adminLogin"));

    document.querySelectorAll(".backBtn").forEach(btn => {
        btn.addEventListener("click", () => show(btn.dataset.target));
    });
}