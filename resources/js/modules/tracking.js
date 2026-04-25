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
 *
 * Status flow
 * ───────────
 *   moving      → clear all banners          | toast: "● GPS Reconnected" (if was disconnected)
 *   idle        → show idle banner            | toast: "Vehicle has stopped"
 *   disconnected→ show no-signal banner       | toast: "GPS signal lost"
 *   shift_ended → show full-screen overlay    | (no toast — overlay is prominent enough)
 */
const GPS_STALE_MS     = 3 * 60 * 1000; // 3 minutes — mirrors backend threshold
const STALE_CHECK_MS   = 30 * 1000;     // check every 30s

//States
let map;
let jeepMarker        = null;
let channel           = null;
let staleCheckInterval = null;
let lastSeenTickerInterval = null;
 
let lastTimestamp    = 0;       // most recent whisper timestamp (ms)
let lastWhisperTime  = 0;       // wall-clock time of last received update
let lastSeenISO      = null;    // ISO string for the last-seen ticker
let previousGpsStatus = null;   // track previous status to fire toasts only on change

export function initTracking() {
    const container = document.getElementById("app");
    if (!app || typeof L === 'undefined') {
        console.error('Tracking: missing #app or Leaflet');
        return;
    }

    const vehicleId      = container.dataset.vehicleId;
    const expectedDriverId = parseInt(container.dataset.driverId);

    seedInfoPanel(app);
 
    initMap();
    loadInitialVehicle(vehicleId, app);
    initRealtime(vehicleId, expectedDriverId);
    startStalenessChecker();
    startLastSeenTicker();
    bindShiftEndedDismiss();
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

// ─── Initial load ─────────────────────────────────────────────────────────────
 
/**
 * Seed the info panel from the data-* attributes the Blade template already
 * rendered. This avoids a visible "flash" of empty/dashed fields before the
 * first API response comes back.
 */
function seedInfoPanel(app) {
    setInfoField('infoRoute',     app.dataset.route     || 'N/A');
    setInfoField('infoDriver',    app.dataset.driverName || 'Unknown');
    setInfoField('infoSpeed',     formatSpeed(parseFloat(app.dataset.speed || 0)));
 
    const shiftStarted = app.dataset.shiftStarted;
    setInfoField('infoShiftStart', shiftStarted ? formatTime(shiftStarted) : '--');
 
    lastSeenISO = app.dataset.lastSeen || null;
    if (lastSeenISO) {
        lastWhisperTime = new Date(lastSeenISO).getTime();
    }
 
    updateCapacityBadge(app.dataset.isFull === '1');
 
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
                placeMarker(vehicle.latitude, vehicle.longitude);
            }
             // Refresh panel with fresher API data (in case Blade data was stale)
            setInfoField('infoRoute',  vehicle.route_name || 'N/A');
            setInfoField('infoSpeed',  formatSpeed(vehicle.speed));
 
            lastSeenISO = vehicle.last_seen || null;
            if (lastSeenISO) {
                lastWhisperTime = new Date(lastSeenISO).getTime();
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
        const derivedStatus = (data.speed ?? 0) < 1 ? 'idle' : 'moving';
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

        // Update panel fields that status-change events carry
        if (vehicle.is_full !== undefined) updateCapacityBadge(vehicle.is_full);
        if (vehicle.route_name)           setInfoField('infoRoute', vehicle.route_name);
 
        lastSeenISO = vehicle.last_seen || lastSeenISO;

        applyGpsStatus(vehicle.gps_status, vehicle.last_seen);
    });
}

// ─── Location received ───────────────────────────────────────────────────────

function onLocationReceived({ lat, lng, speed, gpsStatus, lastSeen, isFull, route }) {
   lastWhisperTime = Date.now();
 
    if (lastSeen) lastSeenISO = lastSeen;
    else          lastSeenISO = new Date(lastWhisperTime).toISOString();
 
    updateMarker(lat, lng);
    setInfoField('infoSpeed', formatSpeed(speed));
    if (route !== undefined)   setInfoField('infoRoute', route || 'N/A');
    if (isFull !== undefined)  updateCapacityBadge(isFull);
 
    applyGpsStatus(gpsStatus, lastSeenISO);
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
 
    switch (gpsStatus) {
        case 'moving':
            hideBanner('noSignalBanner');
            hideBanner('idleBanner');
            setStatusPill('moving');
            if (changed && !silent && previousGpsStatus === 'disconnected') {
                showToast('● GPS signal restored — vehicle is moving', 'success');
            }
            break;
 
        case 'idle':
            hideBanner('noSignalBanner');
            showBanner('idleBanner', '🚌 Vehicle is currently stopped or idling');
            setStatusPill('idle');
            if (changed && !silent) {
                showToast('Vehicle has stopped — waiting for passengers', 'info');
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
    moving:      { dot: '#43A047', text: '● LIVE',      bg: 'rgba(0,0,0,0.65)' },
    idle:        { dot: '#FBC02D', text: '● IDLE',      bg: 'rgba(0,0,0,0.65)' },
    disconnected:{ dot: '#E64A19', text: '◌ NO SIGNAL', bg: 'rgba(0,0,0,0.65)' },
    shift_ended: { dot: '#9E9E9E', text: '■ ENDED',     bg: 'rgba(0,0,0,0.65)' },
};
 
function setStatusPill(gpsStatus) {
    const pill    = document.getElementById('statusPill');
    const dot     = document.getElementById('statusPillDot');
    const textEl  = document.getElementById('statusPillText');
    if (!pill || !dot || !textEl) return;
 
    const cfg = PILL_CONFIG[gpsStatus] || PILL_CONFIG.disconnected;
    dot.style.background    = cfg.dot;
    textEl.textContent      = cfg.text;
    pill.style.background   = cfg.bg;
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
function showToast(message, type = 'info', duration = 4500) {
    const stack = document.getElementById('toastStack');
    if (!stack) return;
 
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `
        <span class="toast-msg">${message}</span>
        <button class="toast-close" aria-label="Dismiss">✕</button>
    `;
 
    toast.querySelector('.toast-close').addEventListener('click', () => dismissToast(toast));
    stack.appendChild(toast);
 
    // Animate in
    requestAnimationFrame(() => toast.classList.add('toast-visible'));
 
    // Auto-dismiss
    setTimeout(() => dismissToast(toast), duration);
}
 
function dismissToast(toast) {
    toast.classList.remove('toast-visible');
    toast.classList.add('toast-exit');
    setTimeout(() => toast.remove(), 350);
}
 
// ─── Shift-ended overlay ──────────────────────────────────────────────────────
 
function showShiftEndedOverlay() {
    clearInterval(staleCheckInterval);
    clearInterval(lastSeenTickerInterval);
 
    hideBanner('noSignalBanner');
    hideBanner('idleBanner');
    setStatusPill('shift_ended');
 
    const overlay = document.getElementById('shiftEndedOverlay');
    if (overlay) {
        overlay.classList.remove('hidden');
    }
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
 
// ─── Marker ───────────────────────────────────────────────────────────────────
 
function placeMarker(lat, lng) {
    if (jeepMarker) {
        jeepMarker.setLatLng([lat, lng]);
    } else {
        jeepMarker = L.marker([lat, lng]).addTo(map);
    }
    map.setView([lat, lng], 16);
}
 
/**
 * Smoothly animate the marker to (lat, lng) over ~250 ms (5 steps × 50 ms).
 * Falls back to placeMarker if jeepMarker hasn't been created yet.
 */
function updateMarker(lat, lng) {
    if (!jeepMarker) {
        placeMarker(lat, lng);
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
 
    map.panTo([lat, lng], { animate: true, duration: 0.25 });
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
 