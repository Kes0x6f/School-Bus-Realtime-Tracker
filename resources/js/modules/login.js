export function initAuthPage(){
    const app = document.getElementById("app");
    if(!app) return;

    const section = ["landing", "studentLogin", "driverLogin"];

    function show(targetId){
        section.forEach(id => {
            const el = document.getElementById(id);
            if (el) el.style.display = (id === targetId) ? "block" : "none";
        });
    }
    
    const toStudent = document.getElementById("toStudent");
    const toDriver = document.getElementById("toDriver");

    toStudent?.addEventListener("click", () => show("studentLogin"));
    toDriver?.addEventListener("click", () => show("driverLogin"));

    // Back buttons
    document.querySelectorAll(".backBtn").forEach(btn => {
        btn.addEventListener("click", () => {
            const target = btn.dataset.target;
            show(target);
        });
    });
}