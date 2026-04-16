/**
 * Active Jeeps Page
 *
 * Shows all vehicles with shift_active = true.
 * Each card displays the vehicle's current gps_status:
 *
 *   moving       → green  ● LIVE
 *   idle         → yellow ● IDLE
 *   disconnected → orange ◌ NO SIGNAL  (with last-seen timestamp)
 *   shift_ended  → remove card entirely
 *
 * Listens on:
 *   private channel `vehicles` — for status changes and location updates (global)
 *   private channel `vehicle.{id}` — per-vehicle location updates (initial list)
 */

export function initActiveJeeps() {
    const container = document.getElementById("vehicleList");
    if (!container || !window.Echo) return;

    const vehicleIds = JSON.parse(container.dataset.vehicleIds);

    subscribeToVehicleChannels(vehicleIds);
    subscribeToGlobalChannel(container);
}

// ─── Channel subscriptions ───────────────────────────────────────────────────

function subscribeToVehicleChannels(vehicleIds) {
    vehicleIds.forEach(id => {
        Echo.private(`vehicle.${id}`)
            .listen('.location.updated', (e) => {
                const el = document.querySelector(`[data-vehicle-id="${e.id}"]`);

                if (!el) {
                    // Vehicle became active but isn't in the DOM yet
                    // (shouldn't normally happen since page loaded from shift_active list,
                    //  but guard anyway)
                    return;
                }

                updateCardStatus(e.id, e.gps_status, e.last_seen, e.speed, e.is_full);
            });
    });
}

function subscribeToGlobalChannel(container) {
    Echo.private('vehicles')
        .listen('.vehicle.status.changed', (e) => {
            console.log("STATUS EVENT:", e);
            const vehicle = e.vehicle;

            if (vehicle.gps_status === 'shift_ended') {
                // Driver ended shift (manually or auto) — remove from list
                removeVehicle(vehicle.id);
                return;
            }

            const exists = document.querySelector(`[data-vehicle-id="${vehicle.id}"]`);

            if (!exists) {
                // New vehicle started a shift while page is open
                addVehicle(container, vehicle);
            } else {
                updateCardStatus(vehicle.id, vehicle.gps_status, vehicle.last_seen, vehicle.speed, vehicle.is_full);
            }
        });
}

// ─── Card management ─────────────────────────────────────────────────────────

function addVehicle(container, vehicle) {
    const a = document.createElement("a");
    a.href = `/student/track/${vehicle.id}`;
    a.className = "jeepCard fade-enter";
    a.dataset.vehicleId = vehicle.id;

    const operator = vehicle.user?.name ?? 'Unknown';
    const route    = vehicle.route_name ?? 'N/A';

    a.innerHTML = buildCardInnerHTML(route, operator, vehicle.is_full, vehicle.gps_status, vehicle.last_seen);

    container.appendChild(a);

    requestAnimationFrame(() => {
        a.classList.remove("fade-enter");
        a.classList.add("fade-enter-active");
    });

    // Subscribe per-vehicle for ongoing location updates
    Echo.private(`vehicle.${vehicle.id}`)
        .listen('.location.updated', (e) => {
            updateCardStatus(e.id, e.gps_status, e.last_seen, e.speed, e.is_full);
        });
}

function removeVehicle(id) {
    const el = document.querySelector(`[data-vehicle-id="${id}"]`);
    if (!el) return;

    el.classList.add("fade-exit");

    requestAnimationFrame(() => {
        el.classList.add("fade-exit-active");
    });

    setTimeout(() => el.remove(), 300);
}

/**
 * Update the status badge on an existing card.
 * Called on both location.updated and vehicle.status.changed events.
 */
function updateCardStatus(id, gpsStatus, lastSeen, speed, is_full) {
    const el = document.querySelector(`[data-vehicle-id="${id}"]`);
    if (!el) return;

    const badge = el.querySelector(".statusBadge");
    if (!badge) return;

    const occupancyBadge = el.querySelector(".occupancyBadge");

    if (typeof is_full !== "undefined" && occupancyBadge) {
        const text = occupancyBadge.querySelector("p");

        if (is_full) {
            occupancyBadge.style.backgroundColor = '#E53935';
            text.textContent = 'FULL';
            text.style.color = '#fff';
        } else {
            occupancyBadge.style.backgroundColor = '#FFD54F';
            text.textContent = 'SEATS AVAILABLE';
            text.style.color = '#E65100';
        }
    }

    el.dataset.lastSeen = lastSeen ?? '';

    const { bgColor, textColor, label } = resolveStatusStyle(gpsStatus, lastSeen);

    badge.style.backgroundColor = bgColor;
    badge.querySelector(".statusText").style.color = textColor;
    badge.querySelector(".statusText").textContent = label;
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Returns badge color + label for a given gps_status.
 *
 * moving       → green,  ● LIVE
 * idle         → yellow, ● IDLE
 * disconnected → orange, ◌ NO SIGNAL  (with human-readable last-seen)
 * shift_ended  → (not shown — card is removed)
 */
function resolveStatusStyle(gpsStatus, lastSeen) {
    switch (gpsStatus) {
        case 'moving':
            return { bgColor: '#43A047', textColor: '#fff', label: '● LIVE' };

        case 'idle':
            return { bgColor: '#FBC02D', textColor: '#4E342E', label: '● IDLE' };

        case 'disconnected': {
            const ago = lastSeen ? formatTimeAgo(lastSeen) : 'unknown time';
            return {
                bgColor:   '#E64A19',
                textColor: '#fff',
                label:     `◌ NO SIGNAL · ${ago}`,
            };
        }

        default:
            return { bgColor: '#9E9E9E', textColor: '#fff', label: '● UNKNOWN' };
    }
}

function buildCardInnerHTML(route, operator, isFull, gpsStatus, lastSeen) {
    const { bgColor, textColor, label } = resolveStatusStyle(gpsStatus, lastSeen);

    return `
        <p class="jeepRoute">Route: ${route}</p>
        <p class="jeepDetail">Operator: ${operator}</p>
        <div class="statusRow">
            <div class="statusBadge" style="background-color: ${bgColor};">
                <p class="statusText" style="color: ${textColor}; font-size: 11px; font-weight: bold;">
                    ${label}
                </p>
            </div>
            <div class="statusBadge occupancyBadge" style="background-color: #FFD54F;">
                <p style="color: #E65100; font-size: 11px; font-weight: bold;">
                    ${isFull ? 'FULL' : 'SEATS AVAILABLE'}
                </p>
            </div>
        </div>
    `;
}

/**
 * Human-readable relative time, e.g. "2 min ago", "45 sec ago".
 * lastSeen is an ISO 8601 string from the server.
 */
function formatTimeAgo(lastSeen) {
    const diffMs  = Date.now() - new Date(lastSeen).getTime();
    const diffSec = Math.floor(diffMs / 1000);

    if (diffSec < 60)  return `${diffSec}s ago`;
    if (diffSec < 3600) return `${Math.floor(diffSec / 60)}m ago`;
    return `${Math.floor(diffSec / 3600)}h ago`;
}