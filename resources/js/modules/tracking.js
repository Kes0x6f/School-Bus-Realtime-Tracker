/**
 * Tracking Page — tracking.js
 *
 * What this module does
 * ─────────────────────
 * 1. Renders a Leaflet map and places a marker at the vehicle's last known position.
 * 2. Receives real-time location updates via Echo whisper (primary) and
 *    the `.location.updated` broadcast event (fallback when whisper is silent > 2 s).
 * 3. Keeps an info panel up-to-date: route, driver, speed, last-seen (live ticker),
 *    shift start time, and passenger capacity.
 * 4. Fires toast notifications *only when the GPS status actually changes* — so the
 *    user isn't spammed on every location ping.
 * 5. Shows/hides banners (no-signal, idle) and a full-screen shift-ended modal.
 * 6. Runs a staleness checker every 30 s that switches the UI to "disconnected"
 *    if no update has arrived for 3+ minutes — giving students immediate feedback
 *    before the server-side cron even fires.
 * 7. Watches the student's own device location and shows:
 *    - Within viewport: a blue "You are here" pulsing dot at their real position
 *    - Outside viewport: a blue arrow-dot pinned to the viewport edge, pointing
 *      outward toward their actual off-screen position. Repositions on every pan.
 *    - Tapping the dot/edge pin locks map focus on the student; a "Follow Jeep"
 *      button appears. Tapping the button or the jeep returns focus to the jeep.
 *    - Distance and compass direction shown in the info panel below the map.
 *
 * Status flow
 * ───────────
 *   moving      → clear all banners          | toast: "● GPS Reconnected" (if was disconnected)
 *   idle        → show idle banner            | toast: "Vehicle has stopped"
 *   disconnected→ show no-signal banner       | toast: "GPS signal lost"
 *   shift_ended → show full-screen overlay    | (no toast — overlay is prominent enough)
 */
const GPS_STALE_MS   = 3 * 60 * 1000; // 3 minutes — mirrors backend threshold
const STALE_CHECK_MS = 30 * 1000;     // check every 30s

// ─── State ────────────────────────────────────────────────────────────────────

let map;
let jeepMarker            = null;
let userMarker            = null;   // "You are here" marker (real pos or edge pin)
let channel               = null;
let staleCheckInterval    = null;
let lastSeenTickerInterval = null;

let focusLockedOnUser = false;  // true → jeep GPS updates don't auto-pan the map
let isUserAtEdge      = false;  // true → marker is currently pinned to viewport edge

let lastTimestamp     = 0;       // most recent whisper timestamp (ms)
let lastWhisperTime   = 0;       // wall-clock time of last received update
let lastSeenISO       = null;    // ISO string for the last-seen ticker
let previousGpsStatus = null;    // track previous status to fire toasts only on change

let jeepLatLng       = null;     // [NEW] { lat, lng } — updated every time jeep moves
let userLatLng       = null;     // [NEW] { lat, lng } — updated by watchPosition
let userWatchId      = null;     // [NEW] geolocation watch handle
let currentJeepStatus = null;   // tracks gps_status so the icon color can be updated in-place

// ─── Init ─────────────────────────────────────────────────────────────────────

export function initTracking() {
    const container = document.getElementById("app");
    if (!container || typeof L === 'undefined') {
        console.error('Tracking: missing #app or Leaflet');
        return;
    }

    const vehicleId       = container.dataset.vehicleId;
    const expectedDriverId = parseInt(container.dataset.driverId);

    seedInfoPanel(container);

    initMap();
    loadInitialVehicle(vehicleId, container);
    initRealtime(vehicleId, expectedDriverId);
    startStalenessChecker();
    startLastSeenTicker();
    bindShiftEndedDismiss();

    // [NEW] Start watching the student's own GPS position.
    // Done last so the map is already initialised before we try to add markers.
    initUserLocation();
}

// ─── Map init ─────────────────────────────────────────────────────────────────

function initMap() {
    map = L.map('map');
    map.setView([16.050889, 120.341236], 15);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap',
    }).addTo(map);

    // Recompute edge-pin position whenever the viewport changes so the arrow
    // always sits at the correct edge and points in the right direction.
    map.on('moveend', () => {
        if (userLatLng && userMarker) updateUserMarkerPosition();
    });

    setTimeout(() => map.invalidateSize(), 500);
}

// ─── Initial load ─────────────────────────────────────────────────────────────

/**
 * Seed the info panel from the data-* attributes the Blade template already
 * rendered. This avoids a visible "flash" of empty/dashed fields before the
 * first API response comes back.
 */
function seedInfoPanel(app) {
    setInfoField('infoRoute',     app.dataset.route      || 'N/A');
    setInfoField('infoDriver',    app.dataset.driverName  || 'Unknown');
    setInfoField('infoSpeed',     formatSpeed(parseFloat(app.dataset.speed || 0)));

    const shiftStarted = app.dataset.shiftStarted;
    setInfoField('infoShiftStart', shiftStarted ? formatTime(shiftStarted) : '--');

    lastSeenISO = app.dataset.lastSeen || null;
    if (lastSeenISO) {
        lastWhisperTime = new Date(lastSeenISO).getTime();
    }

    updateCapacityBadge(app.dataset.isFull === '1');

    // [NEW] Show placeholder text in proximity fields until both positions are known
    setInfoField('infoDistance',  'Locating…');
    setInfoField('infoDirection', '—');

    // Apply initial GPS status (without showing a toast — page just loaded)
    const initialStatus = app.dataset.gpsStatus || 'disconnected';
    applyGpsStatus(initialStatus, lastSeenISO, /* silent = */ true);
}

function loadInitialVehicle(vehicleId, app) {
    fetch(`/api/vehicles/${vehicleId}`)
        .then(res => res.json())
        .then(vehicle => {
            if (!vehicle) return;

            // If shift is already ended before the page even loads, show overlay immediately.
            if (vehicle.gps_status === 'shift_ended') {
                showShiftEndedOverlay();
                return;
            }

            if (vehicle.latitude && vehicle.longitude) {
                placeMarker(vehicle.latitude, vehicle.longitude, vehicle.gps_status);
                // [NEW] Store jeep position so proximity can be computed as soon
                // as the user's own GPS fix comes in.
                jeepLatLng = { lat: vehicle.latitude, lng: vehicle.longitude };
            }

            // Refresh panel with fresher API data (in case Blade data was stale)
            setInfoField('infoRoute', vehicle.route_name || 'N/A');
            setInfoField('infoSpeed', formatSpeed(vehicle.speed));

            lastSeenISO = vehicle.last_seen || null;
            if (lastSeenISO) {
                lastWhisperTime = new Date(lastSeenISO).getTime();
            }

            // Reflect whatever state the vehicle is already in
            applyGpsStatus(vehicle.gps_status, vehicle.last_seen, /* silent= */ true);

            // [NEW] If watchPosition already returned a user fix before the API
            // responded, compute proximity now that we have the jeep position.
            updateProximityInfo();
        })
        .catch(err => console.error("Failed to load vehicle:", err));
}

// ─── Realtime ─────────────────────────────────────────────────────────────────

function initRealtime(vehicleId, expectedDriverId) {
    channel = Echo.private(`vehicle.${vehicleId}`);

    // ── Broadcast fallback (server event) ──────────────────────────────────
    channel.listen('.location.updated', (event) => {
        const now = Date.now();

        // Only use broadcast if whisper has been silent for > 2 s
        if (now - lastTimestamp > 2000) {
            onLocationReceived({
                lat:       event.latitude,
                lng:       event.longitude,
                speed:     event.speed,
                gpsStatus: event.gps_status,
                lastSeen:  event.last_seen,
                isFull:    event.is_full,
                route:     event.route_name,
            });
        }
    });

    // ── Whisper primary ────────────────────────────────────────────────────
    channel.listenForWhisper('location.update', (data) => {
        console.log("WHISPER RECEIVED:", data);

        if (data.driver_id !== expectedDriverId) return;
        if (data.timestamp <= lastTimestamp) return;

        lastTimestamp = data.timestamp;

        const latency = Date.now() - data.timestamp;
        console.log("Whisper latency:", latency, "ms");

        // speed from whisper; gps_status derived locally for immediate response
        // Derive a provisional status from the whisper speed reading.
        // Traffic vs idle requires server-side time context (last_moved_at),
        // so this is a best-effort split corrected by the next broadcast event:
        //   ≥ 3 km/h → moving   (above GPS noise floor)
        //   0.5–3    → traffic  (slow queue or red light)
        //   < 0.5    → idle     (essentially stationary)
        const wSpeed = data.speed ?? 0;
        const derivedStatus = wSpeed >= 3   ? 'moving'
                            : wSpeed >= 0.5 ? 'traffic'
                            :                 'idle';
        onLocationReceived({
            lat:       data.latitude,
            lng:       data.longitude,
            speed:     data.speed,
            gpsStatus: derivedStatus,
            lastSeen:  null, // whisper doesn't carry a DB-persisted last_seen
            isFull:    undefined,
            route:     undefined,
        });
    });

    // ── Status change (shift ended, disconnected, reconnected) ─────────────
    channel.listen('.vehicle.status.changed', (e) => {
        console.log("STATUS CHANGED:", e);
        const vehicle = e.vehicle;

        if (vehicle.gps_status === 'shift_ended') {
            showShiftEndedOverlay();
            return;
        }

        if (vehicle.route_name) {
            const currentRoute = document.getElementById('infoRoute')?.textContent?.trim();
            const newRoute     = vehicle.route_name.trim();

            if (currentRoute && newRoute && currentRoute !== newRoute && currentRoute !== 'N/A') {
                showToast(
                    `🚌 Route changed to ${newRoute}`,
                    'info',
                    8000   // longer duration — students should have time to read it
                );
            }

            setInfoField('infoRoute', newRoute);
        }

        // Update panel fields that status-change events carry
        if (vehicle.is_full !== undefined) updateCapacityBadge(vehicle.is_full);

        lastSeenISO = vehicle.last_seen || lastSeenISO;

        applyGpsStatus(vehicle.gps_status, vehicle.last_seen);
    });
}

// ─── Location received ────────────────────────────────────────────────────────

function onLocationReceived({ lat, lng, speed, gpsStatus, lastSeen, isFull, route }) {
    lastWhisperTime = Date.now();

    if (lastSeen) lastSeenISO = lastSeen;
    else          lastSeenISO = new Date(lastWhisperTime).toISOString();

    updateMarker(lat, lng);

    // [NEW] Keep jeepLatLng current so updateProximityInfo always has fresh data
    jeepLatLng = { lat, lng };
    updateProximityInfo();

    setInfoField('infoSpeed', formatSpeed(speed));
    if (route !== undefined)  setInfoField('infoRoute', route || 'N/A');
    if (isFull !== undefined) updateCapacityBadge(isFull);

    applyGpsStatus(gpsStatus, lastSeenISO);
}

// ─── [NEW] User location ──────────────────────────────────────────────────────

/**
 * Requests the browser's geolocation and watches for changes.
 *
 * - Places a custom blue "You are here" marker.
 * - Draws a dashed polyline from the student to the jeep.
 * - Calls updateProximityInfo() after each fix so distance + direction stay fresh.
 *
 * If the browser refuses permission or doesn't support geolocation, the
 * proximity fields quietly show "Location unavailable" and no marker appears.
 * Everything else on the page continues to work normally.
 */
function initUserLocation() {
    if (!navigator.geolocation) {
        setInfoField('infoDistance',  'Not supported');
        setInfoField('infoDirection', '—');
        return;
    }

    const options = {
        enableHighAccuracy: true,
        maximumAge:         10000,   // accept a cached fix up to 10 s old
        timeout:            15000,
    };

    userWatchId = navigator.geolocation.watchPosition(
        (position) => {
            const { latitude: lat, longitude: lng } = position.coords;
            userLatLng = { lat, lng };
            placeUserMarker(lat, lng);
            updateProximityInfo();

            // If the student has locked focus on themselves, follow their movement.
            if (focusLockedOnUser) {
                map.panTo([lat, lng], { animate: true, duration: 0.25 });
            }
        },
        (err) => {
            console.warn('User geolocation error:', err.message);
            setInfoField('infoDistance',  'Location unavailable');
            setInfoField('infoDirection', '—');
        },
        options
    );
}

/**
 * Ensure the user marker exists, then delegate position + icon to
 * updateUserMarkerPosition() which decides whether to show the real
 * location or the edge pin depending on the current viewport.
 */
function placeUserMarker(lat, lng) {
    if (!map) return;

    if (!userMarker) {
        userMarker = L.marker([lat, lng], {
            icon:         createNormalUserIcon(),
            zIndexOffset: 100,
        })
        .addTo(map);

        userMarker.on('click', onUserMarkerClick);
    }

    updateUserMarkerPosition();
}

/**
 * Decides whether the student is inside the current viewport.
 *
 * In bounds  → place the pulsing dot at the real GPS position.
 * Out of bounds → cast a ray from the viewport centre toward the real position,
 *                 find where it exits the padded rectangle, place a directional
 *                 arrow icon there so the student is never "lost" off-screen.
 *
 * Called on every GPS fix AND on every map moveend event.
 */
function updateUserMarkerPosition() {
    if (!userMarker || !userLatLng || !map) return;

    const realLatLng = L.latLng(userLatLng.lat, userLatLng.lng);
    const inBounds   = map.getBounds().contains(realLatLng);

    if (inBounds) {
        if (isUserAtEdge) {
            isUserAtEdge = false;
            userMarker.setIcon(createNormalUserIcon());
        }
        userMarker.setLatLng(realLatLng);
    } else {
        const { edgeLatLng, bearing } = computeEdgePosition(realLatLng);
        userMarker.setLatLng(edgeLatLng);
        userMarker.setIcon(createEdgeUserIcon(bearing));
        isUserAtEdge = true;
    }
}

/**
 * Cast a ray from the viewport centre toward `targetLatLng` and return the
 * point where that ray exits the inset rectangle (padding = 28 px per edge).
 *
 * Also returns the screen-space bearing (degrees clockwise from up) so the
 * edge icon arrow can point outward toward the student's real position.
 *
 * Geometry: parametric line  P(t) = centre + t * direction, t ≥ 0.
 * We solve for the smallest t > 0 that hits any of the four edges, subject
 * to the intersection lying within the adjacent pair of edges.
 */
function computeEdgePosition(targetLatLng) {
    const padding  = 28;
    const mapSize  = map.getSize();
    const cx       = mapSize.x / 2;
    const cy       = mapSize.y / 2;

    const targetPx = map.latLngToContainerPoint(targetLatLng);
    const dx = targetPx.x - cx;
    const dy = targetPx.y - cy;

    const left   = padding;
    const right  = mapSize.x - padding;
    const top    = padding;
    const bottom = mapSize.y - padding;

    let bestT = Infinity;

    // Left edge (dx < 0 means we're heading left)
    if (dx < 0) {
        const t = (left - cx) / dx;
        const y = cy + t * dy;
        if (y >= top && y <= bottom) bestT = Math.min(bestT, t);
    }
    // Right edge
    if (dx > 0) {
        const t = (right - cx) / dx;
        const y = cy + t * dy;
        if (y >= top && y <= bottom) bestT = Math.min(bestT, t);
    }
    // Top edge (dy < 0 means we're heading up)
    if (dy < 0) {
        const t = (top - cy) / dy;
        const x = cx + t * dx;
        if (x >= left && x <= right) bestT = Math.min(bestT, t);
    }
    // Bottom edge
    if (dy > 0) {
        const t = (bottom - cy) / dy;
        const x = cx + t * dx;
        if (x >= left && x <= right) bestT = Math.min(bestT, t);
    }

    const edgePx = L.point(
        Math.max(left, Math.min(right, cx + bestT * dx)),
        Math.max(top,  Math.min(bottom, cy + bestT * dy))
    );

    // Bearing in screen space: atan2(dx, -dy) gives 0 = up, 90 = right.
    const bearing = (Math.atan2(dx, -dy) * 180 / Math.PI + 360) % 360;

    return {
        edgeLatLng: map.containerPointToLatLng(edgePx),
        bearing,
    };
}

// ─── User marker icons ────────────────────────────────────────────────────────

/** Blue pulsing dot with a permanent "You are here" label — shown when the student is within the viewport. */
function createNormalUserIcon() {
    return L.divIcon({
        className: '',
        html: `
            <div style="display:flex;flex-direction:column;align-items:center;gap:4px;">
                <div style="position:relative;width:18px;height:18px;">
                    <div style="
                        position:absolute;inset:-6px;border-radius:50%;
                        background:rgba(30,136,229,0.20);
                        animation:userPulse 2s ease-out infinite;
                    "></div>
                    <div style="
                        width:18px;height:18px;border-radius:50%;
                        background:#1E88E5;border:2.5px solid #fff;
                        box-shadow:0 1px 6px rgba(0,0,0,0.4);
                    "></div>
                </div>
                <div style="
                    background:rgba(0,0,0,0.62);
                    color:#fff;
                    font-size:10px;
                    font-weight:700;
                    font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
                    padding:3px 8px;
                    border-radius:4px;
                    white-space:nowrap;
                    letter-spacing:0.4px;
                    line-height:1.3;
                    box-shadow:0 1px 4px rgba(0,0,0,0.25);
                ">You are here</div>
            </div>
            <style>
                @keyframes userPulse {
                    0%   { transform:scale(0.6); opacity:0.8; }
                    70%  { transform:scale(2.2); opacity:0;   }
                    100% { transform:scale(2.2); opacity:0;   }
                }
            </style>`,
        // iconSize width covers the label (~82 px); height = dot(18) + gap(4) + label(~20) = 42
        iconSize:   [82, 42],
        iconAnchor: [41, 9],   // x: centre of icon;  y: centre of the dot (18 / 2)
    });
}

/**
 * Blue circle with a white arrow and a permanent "You" label — shown at the
 * viewport edge when the student is off-screen. `bearing` is degrees clockwise
 * from up (screen space), so rotating the SVG arrow by that angle makes it
 * point outward toward the student's actual off-screen position.
 */
function createEdgeUserIcon(bearing) {
    return L.divIcon({
        className: '',
        html: `
            <div style="display:flex;flex-direction:column;align-items:center;gap:4px;">
                <div style="
                    width:30px;height:30px;border-radius:50%;
                    background:#1E88E5;
                    border:2.5px solid #fff;
                    box-shadow:0 2px 10px rgba(0,0,0,0.35);
                    display:flex;align-items:center;justify-content:center;
                ">
                    <svg width="13" height="13" viewBox="0 0 13 13"
                         style="display:block;transform:rotate(${bearing}deg);">
                        <polygon points="6.5,1 12,12 6.5,8.5 1,12" fill="#fff"/>
                    </svg>
                </div>
                <div style="
                    background:rgba(0,0,0,0.62);
                    color:#fff;
                    font-size:10px;
                    font-weight:700;
                    font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
                    padding:3px 8px;
                    border-radius:4px;
                    white-space:nowrap;
                    letter-spacing:0.4px;
                    line-height:1.3;
                    box-shadow:0 1px 4px rgba(0,0,0,0.25);
                ">You</div>
            </div>`,
        // dot(30) + gap(4) + label(~20) = 54 h; label "You" ≈ 34 px wide, dot wider at 30
        iconSize:   [50, 54],
        iconAnchor: [25, 15],   // x: centre; y: centre of the 30 px dot
    });
}

// ─── Focus lock ───────────────────────────────────────────────────────────────

/**
 * Called when the student taps their own marker (real dot or edge pin).
 * Pans to the actual GPS position, locks focus so jeep updates don't steal
 * the pan, and shows the "Follow Jeep" button.
 */
function onUserMarkerClick() {
    if (!userLatLng) return;
    focusLockedOnUser = true;
    map.panTo([userLatLng.lat, userLatLng.lng], { animate: true, duration: 0.4 });
    showFollowJeepButton();
}

/**
 * Returns map focus to the jeep and removes the "Follow Jeep" button.
 * Bound to the jeep marker click and the button itself.
 */
function returnFocusToJeep() {
    focusLockedOnUser = false;
    hideFollowJeepButton();
    if (jeepLatLng) {
        map.panTo([jeepLatLng.lat, jeepLatLng.lng], { animate: true, duration: 0.4 });
    }
}

/** Inject a floating "Follow Jeep 🚌" button inside #mapContainer. */
function showFollowJeepButton() {
    if (document.getElementById('followJeepBtn')) return;

    const btn = document.createElement('button');
    btn.id          = 'followJeepBtn';
    btn.textContent = '🚌 Follow Jeep';
    btn.setAttribute('aria-label', 'Return map focus to the jeep');

    Object.assign(btn.style, {
        position:   'absolute',
        bottom:     '48px',        // clear of the Leaflet attribution
        left:       '50%',
        transform:  'translateX(-50%)',
        zIndex:     '1001',
        background: 'var(--c-primary, #002D62)',
        color:      '#fff',
        border:     'none',
        padding:    '8px 20px',
        borderRadius: '20px',
        fontSize:   '13px',
        fontWeight: '600',
        cursor:     'pointer',
        boxShadow:  '0 2px 12px rgba(0,0,0,0.28)',
        fontFamily: 'inherit',
        whiteSpace: 'nowrap',
        transition: 'opacity 0.2s',
    });

    btn.addEventListener('click', returnFocusToJeep);
    document.getElementById('mapContainer').appendChild(btn);
}

function hideFollowJeepButton() {
    document.getElementById('followJeepBtn')?.remove();
}

// ─── Proximity calculation ────────────────────────────────────────────────────

/**
 * Recalculate and render distance + direction whenever either position changes.
 * Safe to call with incomplete state — returns early if either position is null.
 */
function updateProximityInfo() {
    if (!userLatLng || !jeepLatLng) return;

    const distM   = haversineDistance(userLatLng.lat, userLatLng.lng, jeepLatLng.lat, jeepLatLng.lng);
    const bearing = getBearing(userLatLng.lat, userLatLng.lng, jeepLatLng.lat, jeepLatLng.lng);
    const { label: cardinalLabel, arrow } = bearingToCardinal(bearing);

    setInfoField('infoDistance',  formatDistance(distM));
    setInfoField('infoDirection', `${arrow} ${cardinalLabel}`);
}

/**
 * Haversine formula — returns distance in metres between two lat/lng points.
 */
function haversineDistance(lat1, lng1, lat2, lng2) {
    const R    = 6371000; // Earth's radius in metres
    const toRad = deg => (deg * Math.PI) / 180;

    const dLat = toRad(lat2 - lat1);
    const dLng = toRad(lng2 - lng1);

    const a =
        Math.sin(dLat / 2) ** 2 +
        Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng / 2) ** 2;

    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

/**
 * Returns the forward azimuth (bearing) in degrees [0, 360) from point 1 to point 2.
 * 0° = North, 90° = East, 180° = South, 270° = West.
 */
function getBearing(lat1, lng1, lat2, lng2) {
    const toRad = deg => (deg * Math.PI) / 180;
    const toDeg = rad => (rad * 180) / Math.PI;

    const dLng = toRad(lng2 - lng1);
    const lat1R = toRad(lat1);
    const lat2R = toRad(lat2);

    const x = Math.sin(dLng) * Math.cos(lat2R);
    const y = Math.cos(lat1R) * Math.sin(lat2R) - Math.sin(lat1R) * Math.cos(lat2R) * Math.cos(dLng);

    return (toDeg(Math.atan2(x, y)) + 360) % 360;
}

/**
 * Maps a bearing (0–360°) to one of 8 cardinal / intercardinal labels + arrow emoji.
 *
 * Sectors are 45° wide, centred on each direction:
 *   North     : 337.5° – 22.5°
 *   Northeast : 22.5°  – 67.5°
 *   … and so on
 */
function bearingToCardinal(bearing) {
    const directions = [
        { label: 'North',     arrow: '↑' },
        { label: 'Northeast', arrow: '↗' },
        { label: 'East',      arrow: '→' },
        { label: 'Southeast', arrow: '↘' },
        { label: 'South',     arrow: '↓' },
        { label: 'Southwest', arrow: '↙' },
        { label: 'West',      arrow: '←' },
        { label: 'Northwest', arrow: '↖' },
    ];

    // Each sector is 45°; offset by 22.5° so North is centred on 0°
    const index = Math.round(bearing / 45) % 8;
    return directions[index];
}

/**
 * Format metres into a human-readable string.
 *   < 1 000 m → "350 m"
 *   ≥ 1 000 m → "1.2 km"
 */
function formatDistance(metres) {
    if (metres < 1000) return `${Math.round(metres)} m`;
    return `${(metres / 1000).toFixed(1)} km`;
}

// ─── GPS status → UI ──────────────────────────────────────────────────────────

/**
 * Apply the correct UI for a given gps_status.
 * Toasts fire only when the status *changes* (not on every update ping).
 * Pass silent = true to skip the toast (used on initial page load).
 *
 * moving      → clear all banners
 * idle        → idle banner
 * disconnected→ no-signal banner
 * shift_ended → full-screen overlay (handled via showShiftEndedOverlay)
 */
function applyGpsStatus(gpsStatus, lastSeen, silent = false) {
    const changed = gpsStatus !== previousGpsStatus;

    // Keep the jeep icon color in sync with current status
    currentJeepStatus = gpsStatus;
    updateJeepMarkerIcon(gpsStatus);

    switch (gpsStatus) {
        case 'moving':
            hideBanner('noSignalBanner');
            hideBanner('idleBanner');
            setStatusPill('moving');
            if (changed && !silent && previousGpsStatus === 'disconnected') {
                showToast('● GPS signal restored — vehicle is moving', 'success');
            }
            break;

        case 'traffic':
            hideBanner('noSignalBanner');
            showBanner('idleBanner', '🚦 Jeep is in traffic — moving slowly');
            setStatusPill('traffic');
            if (changed && !silent) {
                showToast('Jeep is caught in traffic', 'warning');
            }
            break;

        case 'idle':
            hideBanner('noSignalBanner');
            showBanner('idleBanner', '🚌 Vehicle is stopped — waiting for passengers');
            setStatusPill('idle');
            if (changed && !silent) {
                showToast('Jeep is idle — waiting for passengers', 'info');
            }
            break;

        case 'disconnected': {
            hideBanner('idleBanner');
            const ago = lastSeen ? formatTimeAgo(lastSeen) : 'unknown time';
            showBanner('noSignalBanner', `⚠ No GPS signal · Last update: ${ago}`, 'noSignalBannerText');
            setStatusPill('disconnected');
            if (changed && !silent) {
                showToast('GPS signal lost. Last seen: ' + ago, 'danger', 6000);
            }
            break;
        }

        case 'shift_ended':
            showShiftEndedOverlay();
            return; // don't update previousGpsStatus here — overlay handles it
    }

    previousGpsStatus = gpsStatus;
}

// ─── Status pill (floating on map) ────────────────────────────────────────────

const PILL_CONFIG = {
    moving:       { dot: '#43A047', text: '● LIVE',      bg: 'rgba(0,0,0,0.65)' },
    traffic:      { dot: '#F57C00', text: '🚦 TRAFFIC',  bg: 'rgba(0,0,0,0.65)' },
    idle:         { dot: '#FBC02D', text: '● IDLE',      bg: 'rgba(0,0,0,0.65)' },
    disconnected: { dot: '#E64A19', text: '◌ NO SIGNAL', bg: 'rgba(0,0,0,0.65)' },
    shift_ended:  { dot: '#9E9E9E', text: '■ ENDED',     bg: 'rgba(0,0,0,0.65)' },
};

function setStatusPill(gpsStatus) {
    const pill   = document.getElementById('statusPill');
    const dot    = document.getElementById('statusPillDot');
    const textEl = document.getElementById('statusPillText');
    if (!pill || !dot || !textEl) return;

    const cfg = PILL_CONFIG[gpsStatus] || PILL_CONFIG.disconnected;
    dot.style.background  = cfg.dot;
    textEl.textContent    = cfg.text;
    pill.style.background = cfg.bg;
}

// ─── Banners ──────────────────────────────────────────────────────────────────

function showBanner(id, message) {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = message;
    el.classList.remove('hidden');
}

function hideBanner(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.add('hidden');
}

// ─── Toast notifications ──────────────────────────────────────────────────────

/**
 * showToast(message, type, duration)
 *
 * type:     'success' | 'info' | 'danger' | 'warning'
 * duration: ms before auto-dismiss (default 4500)
 */
/* function showToastLegacy(message, type = 'info', duration = 4500) {
    return showToast(message, type, duration);

    const stack = document.getElementById('toastStack');
    if (!stack) return;

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.textContent = `
        <span class="toast-msg">${message}</span>
        <button class="toast-close" aria-label="Dismiss">✕</button>
    `;

    toast.querySelector('.toast-close').addEventListener('click', () => dismissToast(toast));
    stack.appendChild(toast);

    requestAnimationFrame(() => toast.classList.add('toast-visible'));

    setTimeout(() => dismissToast(toast), duration);
} */

function dismissToast(toast) {
    toast.classList.remove('toast-visible');
    toast.classList.add('toast-exit');
    setTimeout(() => toast.remove(), 350);
}

// Keep toast content as a text node. Route names and other event data are
// untrusted even when they came from an authenticated driver.
function showToast(message, type = 'info', duration = 4500) {
    const stack = document.getElementById('toastStack');
    if (!stack) return;

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;

    const messageElement = document.createElement('span');
    messageElement.className = 'toast-msg';
    messageElement.textContent = message;

    const closeButton = document.createElement('button');
    closeButton.className = 'toast-close';
    closeButton.type = 'button';
    closeButton.setAttribute('aria-label', 'Dismiss');
    closeButton.textContent = '\u00D7';

    toast.append(messageElement, closeButton);
    closeButton.addEventListener('click', () => dismissToast(toast));
    stack.appendChild(toast);

    requestAnimationFrame(() => toast.classList.add('toast-visible'));
    setTimeout(() => dismissToast(toast), duration);
}

// ─── Shift-ended overlay ──────────────────────────────────────────────────────

function showShiftEndedOverlay() {
    clearInterval(staleCheckInterval);
    clearInterval(lastSeenTickerInterval);

    // [NEW] Stop watching user location — no more jeep to track
    if (userWatchId !== null) {
        navigator.geolocation.clearWatch(userWatchId);
        userWatchId = null;
    }

    hideBanner('noSignalBanner');
    hideBanner('idleBanner');
    setStatusPill('shift_ended');

    const overlay = document.getElementById('shiftEndedOverlay');
    if (overlay) overlay.classList.remove('hidden');
}

/** "Stay on this page" button — dismisses the modal so students can see the last map position */
function bindShiftEndedDismiss() {
    const btn = document.getElementById('shiftEndedDismiss');
    btn?.addEventListener('click', () => {
        const overlay = document.getElementById('shiftEndedOverlay');
        overlay?.classList.add('hidden');
        showToast('Shift has ended — map shows last known position', 'warning', 8000);
    });
}

// ─── Info panel helpers ───────────────────────────────────────────────────────

function setInfoField(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
}

function updateCapacityBadge(isFull) {
    const badge = document.getElementById('infoCapacityBadge');
    if (!badge) return;
    badge.textContent = isFull ? 'FULL' : 'SEATS AVAILABLE';
    badge.className   = `capacity-badge ${isFull ? 'cap-full' : 'cap-available'}`;
}

// ─── Staleness checker ────────────────────────────────────────────────────────

/**
 * Runs every 30 s. If no update has arrived for GPS_STALE_MS (3 min),
 * proactively switch to "disconnected" — giving students immediate feedback
 * before the server-side cron even fires.
 */
function startStalenessChecker() {
    staleCheckInterval = setInterval(() => {
        if (lastWhisperTime === 0) return;

        const ms = Date.now() - lastWhisperTime;
        if (ms >= GPS_STALE_MS) {
            const iso = new Date(lastWhisperTime).toISOString();
            applyGpsStatus('disconnected', iso);
        }
    }, STALE_CHECK_MS);
}

// ─── Live last-seen ticker ────────────────────────────────────────────────────

/**
 * Ticks every second to keep #infoLastSeen fresh (e.g. "12s ago", "3m ago")
 * without requiring a server round-trip.
 */
function startLastSeenTicker() {
    lastSeenTickerInterval = setInterval(() => {
        const el = document.getElementById('infoLastSeen');
        if (!el) return;

        if (!lastSeenISO) {
            el.textContent = '--';
            return;
        }

        el.textContent = formatTimeAgo(lastSeenISO);
    }, 1000);
}

// ─── Jeep marker ─────────────────────────────────────────────────────────────

/**
 * Build a status-aware divIcon for the jeep.
 *
 * Shape:  rounded square (clearly a vehicle, not a "you are here" dot)
 * Colors: navy when moving, amber when idle, grey when disconnected
 * Size:   36 × 36 px — prominent but not overbearing
 *
 * Keeping it distinct from the user's circular pulsing blue dot is the key
 * goal — students should never confuse the two at a glance.
 */
function createJeepIcon(gpsStatus) {
    const bgColor = gpsStatus === 'moving'       ? '#002D62'  // navy
                  : gpsStatus === 'idle'          ? '#E65100'  // deep orange (warm, not blue)
                  : gpsStatus === 'disconnected'  ? '#757575'  // grey
                  : '#757575';                                  // shift_ended / unknown

    return L.divIcon({
        className: '',  // suppress Leaflet's default white square
        html: `
            <div style="
                width: 36px;
                height: 36px;
                border-radius: 10px;
                background: ${bgColor};
                border: 2.5px solid #fff;
                box-shadow: 0 2px 10px rgba(0,0,0,0.35);
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 20px;
                line-height: 1;
                transition: background 0.3s;
            ">🚌</div>`,
        iconSize:    [36, 36],
        iconAnchor:  [18, 18],
        tooltipAnchor: [0, -20],
    });
}

/**
 * Swap just the icon color when GPS status changes — no position update needed.
 * Called from applyGpsStatus so the marker reflects the current state.
 */
function updateJeepMarkerIcon(gpsStatus) {
    if (!jeepMarker) return;
    jeepMarker.setIcon(createJeepIcon(gpsStatus));
}

function placeMarker(lat, lng, gpsStatus = 'disconnected') {
    if (jeepMarker) {
        jeepMarker.setLatLng([lat, lng]);
    } else {
        jeepMarker = L.marker([lat, lng], {
            icon:          createJeepIcon(gpsStatus),
            zIndexOffset:  1000,   // always above the user dot
        })
        .bindTooltip('Jeep', { permanent: false, direction: 'top', offset: [0, -6] })
        .addTo(map);

        // Tapping the jeep returns map focus to it when the student has
        // previously locked focus on their own location.
        jeepMarker.on('click', returnFocusToJeep);
    }
    map.setView([lat, lng], 16);
}

/**
 * Smoothly animate the marker to (lat, lng) over ~250 ms (5 steps × 50 ms).
 * Falls back to placeMarker if jeepMarker hasn't been created yet.
 */
function updateMarker(lat, lng) {
    if (!jeepMarker) {
        placeMarker(lat, lng, currentJeepStatus ?? 'disconnected');
        return;
    }

    const current = jeepMarker.getLatLng();
    const target  = L.latLng(lat, lng);
    const steps   = 5;
    let i = 0;

    const interval = setInterval(() => {
        i++;
        const t      = i / steps;
        const newLat = current.lat + (target.lat - current.lat) * t;
        const newLng = current.lng + (target.lng - current.lng) * t;
        jeepMarker.setLatLng([newLat, newLng]);
        if (i >= steps) clearInterval(interval);
    }, 50);

    // Don't steal the pan if the student has locked focus on themselves.
    if (!focusLockedOnUser) {
        map.panTo([lat, lng], { animate: true, duration: 0.25 });
    }
}

// ─── Formatters ───────────────────────────────────────────────────────────────

function formatTimeAgo(isoString) {
    const diffSec = Math.floor((Date.now() - new Date(isoString).getTime()) / 1000);
    if (diffSec < 5)    return 'Just now';
    if (diffSec < 60)   return `${diffSec}s ago`;
    if (diffSec < 3600) return `${Math.floor(diffSec / 60)}m ago`;
    return `${Math.floor(diffSec / 3600)}h ago`;
}

function formatSpeed(speed) {
    const s = parseFloat(speed);
    if (isNaN(s)) return '-- km/h';
    return `${Math.round(s)} km/h`;
}

function formatTime(isoString) {
    if (!isoString) return '--';
    return new Date(isoString).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}
