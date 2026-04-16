/**
 * Tracking Page
 *
 * Displays a single vehicle's real-time location on a Leaflet map.
 *
 * State awareness:
 *   - On load: checks vehicle's current gps_status from API and renders correct initial UI.
 *   - Whisper (primary): updates marker + resets staleness timer.
 *   - Broadcast fallback: used when whisper is silent for > 2s.
 *   - Staleness checker: runs every 30s.
 *       → 3+ min no update  → shows "No Signal" banner.
 *       → GPS resumes       → banner dismissed.
 *   - vehicle.status.changed event: responds to shift-ended and disconnected transitions.
 *
 * UI overlays / banners:
 *   #noSignalBanner    — shown when GPS hasn't updated for 3+ min (disconnected)
 *   #idleBanner        — shown when vehicle is moving but speed = 0 (idle)
 *   #shiftEndedOverlay — full-page overlay shown when shift ends
 *
 * Required HTML in the Blade view:
 *   <div id="mapContainer" data-vehicle-id="{{ $jeepId }}" data-driver-id="{{ $vehicle->user_id }}">
 *   <div id="map"></div>
 *   <div id="noSignalBanner" class="trackingBanner hidden">...</div>
 *   <div id="idleBanner" class="trackingBanner hidden">...</div>
 *   <div id="shiftEndedOverlay" class="trackingOverlay hidden">...</div>
 */

const GPS_STALE_MS     = 3 * 60 * 1000; // 3 minutes — mirrors backend threshold
const STALE_CHECK_MS   = 30 * 1000;     // check every 30s

let lastTimestamp    = 0;
let lastWhisperTime  = 0; // wall-clock time we last received any update (whisper or broadcast)
let map;
let jeepMarker = null;
let channel    = null;
let staleCheckInterval = null;

export function initTracking() {
    const container = document.getElementById("mapContainer");
    if (!container) return;

    if (typeof L === "undefined") {
        console.error("Leaflet (L) is not loaded");
        return;
    }

    const vehicleId      = container.dataset.vehicleId;
    const expectedDriverId = parseInt(container.dataset.driverId);

    initMap();
    loadInitialVehicle(vehicleId);
    initRealtime(vehicleId, expectedDriverId);
    startStalenessChecker();
}

// ─── Map init ────────────────────────────────────────────────────────────────

function initMap() {
    map = L.map('map');
    map.setView([16.050889, 120.341236], 15);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap',
    }).addTo(map);

    setTimeout(() => map.invalidateSize(), 500);
}

// ─── Initial load ────────────────────────────────────────────────────────────

function loadInitialVehicle(vehicleId) {
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
                jeepMarker = L.marker([vehicle.latitude, vehicle.longitude]).addTo(map);
                map.setView([vehicle.latitude, vehicle.longitude], 16);
            }

            // Reflect whatever state the vehicle is already in
            applyGpsStatus(vehicle.gps_status, vehicle.last_seen);
        })
        .catch(err => console.error("Failed to load vehicle:", err));
}

// ─── Realtime ────────────────────────────────────────────────────────────────

function initRealtime(vehicleId, expectedDriverId) {
    channel = Echo.private(`vehicle.${vehicleId}`);

    // ── Broadcast fallback (server event) ──────────────────────────────────
    channel.listen('.location.updated', (event) => {
        const now = Date.now();

        // Only use broadcast if whisper has been silent for > 2s
        if (now - lastTimestamp > 2000) {
            console.log("Using broadcast fallback");
            onLocationReceived(event.latitude, event.longitude, event.speed, event.gps_status, event.last_seen);
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
        const derivedStatus = (data.speed ?? 0) < 1 ? 'idle' : 'moving';
        onLocationReceived(data.latitude, data.longitude, data.speed, derivedStatus, null);
    });

    // ── Status change (shift ended, disconnected, reconnected) ─────────────
    channel.listen('.vehicle.status.changed', (e) => {
        console.log("STATUS CHANGED:", e);
        const vehicle = e.vehicle;

        if (vehicle.gps_status === 'shift_ended') {
            showShiftEndedOverlay();
            return;
        }

        applyGpsStatus(vehicle.gps_status, vehicle.last_seen);
    });
}

// ─── Location received ───────────────────────────────────────────────────────

function onLocationReceived(lat, lng, speed, gpsStatus, lastSeen) {
    lastWhisperTime = Date.now();

    updateMarker(lat, lng);
    applyGpsStatus(gpsStatus, lastSeen);
}

// ─── Staleness checker ───────────────────────────────────────────────────────

/**
 * Runs every 30 seconds.
 * If we haven't received any update for GPS_STALE_MS (3 min),
 * switch UI to "disconnected" state proactively — even before the
 * server's CheckInactiveVehicles command fires.
 *
 * This gives students immediate feedback in the browser
 * rather than waiting for the next server-side cron tick.
 */
function startStalenessChecker() {
    staleCheckInterval = setInterval(() => {
        if (lastWhisperTime === 0) return; // haven't received any update yet

        const msSinceUpdate = Date.now() - lastWhisperTime;

        if (msSinceUpdate >= GPS_STALE_MS) {
            const isoLastSeen = new Date(lastWhisperTime).toISOString();
            applyGpsStatus('disconnected', isoLastSeen);
        }
    }, STALE_CHECK_MS);
}

// ─── UI state ────────────────────────────────────────────────────────────────

/**
 * Apply the correct UI for a given gps_status.
 *
 * moving       → dismiss all banners
 * idle         → show idle banner, dismiss no-signal
 * disconnected → show no-signal banner with last-seen time, dismiss idle
 * shift_ended  → show full overlay (handled separately via showShiftEndedOverlay)
 */
function applyGpsStatus(gpsStatus, lastSeen) {
    switch (gpsStatus) {
        case 'moving':
            hideBanner('noSignalBanner');
            hideBanner('idleBanner');
            break;

        case 'idle':
            hideBanner('noSignalBanner');
            showBanner('idleBanner', 'Vehicle is currently stopped or idling.');
            break;

        case 'disconnected': {
            hideBanner('idleBanner');
            const ago = lastSeen ? formatTimeAgo(lastSeen) : 'unknown time';
            showBanner('noSignalBanner', `No GPS signal · Last update: ${ago}`);
            break;
        }

        case 'shift_ended':
            showShiftEndedOverlay();
            break;
    }
}

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

/**
 * Full-page overlay shown when the driver ends their shift (or auto-end fires).
 * Stops the staleness checker since there's nothing left to track.
 * Provides a link back to the active jeeps list.
 */
function showShiftEndedOverlay() {
    clearInterval(staleCheckInterval);
    hideBanner('noSignalBanner');
    hideBanner('idleBanner');

    const overlay = document.getElementById('shiftEndedOverlay');
    if (overlay) {
        overlay.classList.remove('hidden');
        return;
    }

    // Fallback: create overlay dynamically if Blade doesn't have one
    const div = document.createElement('div');
    div.id = 'shiftEndedOverlay';
    div.style.cssText = `
        position: fixed; inset: 0; background: rgba(0,0,0,0.75);
        display: flex; flex-direction: column;
        align-items: center; justify-content: center;
        color: #fff; text-align: center; z-index: 9999;
        gap: 16px; padding: 24px;
    `;
    div.innerHTML = `
        <p style="font-size: 22px; font-weight: bold;">🚌 Shift Ended</p>
        <p style="font-size: 15px; opacity: 0.85;">
            This jeepney has ended its shift and is no longer active.
        </p>
        <a href="/student/active-jeeps"
           style="background:#43A047; color:#fff; padding: 12px 24px;
                  border-radius: 8px; text-decoration: none; font-weight: bold;">
            View Active Jeepneys
        </a>
    `;

    document.body.appendChild(div);
}

// ─── Marker animation ────────────────────────────────────────────────────────

function updateMarker(lat, lng) {
    if (!jeepMarker) return;

    const current = jeepMarker.getLatLng();
    const target  = L.latLng(lat, lng);
    const steps   = 5;
    let i = 0;

    const interval = setInterval(() => {
        i++;
        const newLat = current.lat + (target.lat - current.lat) * (i / steps);
        const newLng = current.lng + (target.lng - current.lng) * (i / steps);
        jeepMarker.setLatLng([newLat, newLng]);

        if (i >= steps) clearInterval(interval);
    }, 50);

    map.panTo([lat, lng]);
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function formatTimeAgo(isoString) {
    const diffSec = Math.floor((Date.now() - new Date(isoString).getTime()) / 1000);
    if (diffSec < 60)   return `${diffSec}s ago`;
    if (diffSec < 3600) return `${Math.floor(diffSec / 60)}m ago`;
    return `${Math.floor(diffSec / 3600)}h ago`;
}