let polling = false;

export function initActiveJeeps(){
    const container = document.getElementById("vehicleList");
    if (!container || !window.Echo) return;

    const vehicleIds = JSON.parse(container.dataset.vehicleIds);

    subscribeToVehicle(vehicleIds);
    subscribeToGlobal(container);
}

function subscribeToVehicle(vehicleIds){
    vehicleIds.forEach(id => {
        Echo.private(`vehicle.${id}`)
            .listen('.location.updated', (e) => {
                console.log("EVENT RECEIVED:", e);

                const exists = document.querySelector(
                    `[data-vehicle-id="${e.id}"]`
                );

                if (!exists) {
                    // vehicle became active but not in list yet
                    addVehicle(document.getElementById("vehicleList"), {
                        id: e.id,
                        route_name: 'N/A', // fallback
                        user: { name: 'Unknown' },
                        is_full: false
                    });
                } else {
                    updateVehicleCard(e);
                }
            });
    });
}

function markVehicleLive(id){
    const el = document.querySelector(`[data-vehicle-id="${id}"]`);
    if (!el) return;

    const status = el.querySelector(".statusText");
    if (status) {
        status.textContent = "● LIVE";
    }
}

function subscribeToGlobal(container){
    Echo.private('vehicles')
        .listen('.vehicle.status.changed', (e) => {
            console.log("STATUS EVENT:", e);
            const vehicle = e.vehicle;

            const exists = document.querySelector(
                `[data-vehicle-id="${vehicle.id}"]`
            );

            if (vehicle.is_active) {
                if (!exists) {
                    addVehicle(container, vehicle);
                }
            } else {
                removeVehicle(vehicle.id);
            }
        });
}

function removeVehicle(id){
    const el = document.querySelector(`[data-vehicle-id="${id}"]`);
    if (!el) return;

    el.classList.add("fade-exit");

    requestAnimationFrame(() => {
        el.classList.add("fade-exit-active");
    });

    setTimeout(() => {
        el.remove();
    }, 300);
}

function addVehicle(container, vehicle){
    const a = document.createElement("a");
    a.href = `/student/track/${vehicle.id}`;
    a.className = "jeepCard fade-enter";
    a.dataset.vehicleId = vehicle.id;

    const operator = vehicle.user?.name ?? 'Unknown';
    const route = vehicle.route_name ?? 'N/A';
    const isFull = vehicle.is_full;

    a.innerHTML = `
        <p class="jeepRoute">
            Route: ${route}
        </p>

        <p class="jeepDetail">
            Operator: ${operator}
        </p>

        <div class="statusRow">

            <div class="statusBadge">
                <p class="statusText">● LIVE</p>
            </div>

            <div class="statusBadge" style="background-color: #FFD54F;">
                <p style="color: #E65100; font-size: 11px; font-weight: bold;">
                    ${isFull ? 'FULL' : 'SEATS AVAILABLE'}
                </p>
            </div>

        </div>
    `;

    container.appendChild(a);

    requestAnimationFrame(() => {
        a.classList.remove("fade-enter");      
        a.classList.add("fade-enter-active");
    });

    // subscribe to realtime updates
    Echo.private(`vehicle.${vehicle.id}`)
        .listen('.location.updated', () => {
            markVehicleLive(vehicle.id);
        });
}

function updateVehicleCard(vehicle){
    const el = document.querySelector(`[data-vehicle-id="${vehicle.id}"]`);
    if (!el) return;

    const routeEl = el.querySelector(".jeepRoute");

    if (routeEl && vehicle.route_name) {
        routeEl.textContent = `Route: ${vehicle.route_name}`;
    }

    // Example: update speed or any visual indicator
    let status = el.querySelector(".statusText");

    if (status) {
        status.textContent = "● LIVE";
    }

    // Optional: add last seen indicator
    el.dataset.lastSeen = vehicle.last_seen;
}
