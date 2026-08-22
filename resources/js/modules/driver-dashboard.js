/**
 * driver-dashboard.js — with full inline feedback & sticky header
 *
 * Changes vs previous version:
 *  - Shift start  → spinner + card border pulse green on success
 *  - Shift end    → spinner + card border pulse red on success
 *  - Route change → inline "✓ Saved" micro-label + green border ring (no toast)
 *  - Broadcast    → live "recording" ring on button while active + duration counter
 *  - Capacity     → scale bounce animation on toggle
 *  - Scroll bg    → .fullScreen changed to min-height (CSS patch handles html/body)
 *  - Sticky strip → pinned top strip (plate + shift dot + GPS status)
 */

let broadcasting  = false;
let watchId       = null;
let channel       = null;
let channelReady  = false;
let shiftActive   = false;
let lastServerSync = 0;
let broadcastStart = 0;          // wall-clock ms when broadcast started
let broadcastTimer = null;       // setInterval handle for the duration counter

const SERVER_INTERVAL = 5000;

// ─── Init ─────────────────────────────────────────────────────────────────────

export function initDriverDashboard() {
    const app = document.getElementById('app');
    if (!app) return;

    shiftActive = app.dataset.shiftActive === '1';
    syncShiftUI();

    if (!window.Echo) { console.error('Echo not loaded'); return; }

    const vehicleId  = app.dataset.vehicleId;
    const userId     = app.dataset.userId;
    const csrfToken  = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    setupChannel(vehicleId);
    bindShiftButtons(vehicleId, csrfToken);
    bindBroadcastToggle(vehicleId, userId, csrfToken);
    bindBusStatusButton();
    bindRouteSelect(csrfToken);

    updateShiftDot();
    setShiftStatusText(shiftActive ? 'Shift Active' : 'Off Duty');
    updateBusUI(app.dataset.isFull === '1');
    updateStickyStrip();
}

// ─── Channel ──────────────────────────────────────────────────────────────────

function setupChannel(vehicleId) {
    channel = window.Echo.private(`vehicle.${vehicleId}`);
    channel.subscribed(() => { channelReady = true; });
    channel.error(err => console.error('Channel error:', err));
}

// ─── Shift ────────────────────────────────────────────────────────────────────

function bindShiftButtons(vehicleId, csrfToken) {
    const startBtn = document.getElementById('startShiftBtn');
    const endBtn   = document.getElementById('endShiftBtn');

    startBtn?.addEventListener('click', async () => {
        const routeName = document.getElementById('routeSelect')?.value ?? null;
        setSpinner(startBtn, true, 'Starting…');

        try {
            const res  = await fetch('/driver/shift/start', {
                method:      'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept':       'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({ route_name: routeName }),
            });

            const data = await res.json();

            if (res.ok) {
                shiftActive = true;
                syncShiftUI();
                setShiftStatusText('Shift Active');
                pulseCard('shift-card', 'success');   // green border flash
                updateStickyStrip();
            } else {
                alert(data.message ?? 'Failed to start shift.');
                setSpinner(startBtn, false, 'Start Shift');
            }
        } catch (err) {
            console.error('Shift start error:', err);
            setSpinner(startBtn, false, 'Start Shift');
        }
    });

    endBtn?.addEventListener('click', async () => {
        if (!confirm('End your shift? You will be removed from the active list.')) return;

        if (broadcasting) {
            stopGPS();
            broadcasting = false;
            syncBroadcastUI();
        }

        setSpinner(endBtn, true, 'Ending…');

        try {
            const res  = await fetch('/driver/shift/end', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            });

            const data = await res.json();

            if (res.ok) {
                shiftActive = false;
                syncShiftUI();
                setShiftStatusText('Shift Ended');
                setGpsStatusText('Offline');
                pulseCard('shift-card', 'danger');     // red border flash
                updateStickyStrip();
            } else {
                alert(data.message ?? 'Failed to end shift.');
                setSpinner(endBtn, false, 'End Shift');
            }
        } catch (err) {
            console.error('Shift end error:', err);
            setSpinner(endBtn, false, 'End Shift');
        }
    });
}

// ─── Route select — inline micro-feedback ─────────────────────────────────────

function bindRouteSelect(csrfToken) {
    const select = document.getElementById('routeSelect');
    if (!select) return;

    select.addEventListener('change', async () => {
        if (!shiftActive) return;   // API rejects mid-shift route change when no shift

        const routeName = select.value;

        try {
            const res = await fetch('/api/driver/route', {
                method:      'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept':       'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({ route_name: routeName }),
            });

            if (res.ok) {
                showRouteSavedHint(select);
            }
        } catch (err) {
            console.warn('Route update failed:', err);
        }
    });
}

/**
 * Show a small "✓ Saved" label below the select, then fade it out.
 * Also flashes a brief green border ring on the select element.
 */
function showRouteSavedHint(selectEl) {
    // Border ring
    selectEl.classList.add('input-saved');
    setTimeout(() => selectEl.classList.remove('input-saved'), 1400);

    // Micro-label
    let hint = document.getElementById('routeSavedHint');
    if (!hint) {
        hint = document.createElement('p');
        hint.id        = 'routeSavedHint';
        hint.className = 'route-saved-hint';
        selectEl.insertAdjacentElement('afterend', hint);
    }

    hint.textContent = '✓ Route saved';
    hint.classList.remove('hint-fade-out');
    hint.style.opacity = '1';

    setTimeout(() => {
        hint.classList.add('hint-fade-out');
    }, 1200);
}

// ─── GPS broadcast ────────────────────────────────────────────────────────────

function bindBroadcastToggle(vehicleId, userId, csrfToken) {
    const btn = document.getElementById('broadcastBtn');

    btn?.addEventListener('click', () => {
        if (!shiftActive) {
            alert('Start your shift first before broadcasting.');
            return;
        }

        if (!channelReady) {
            setGpsStatusText('Connecting…');
            return;
        }

        broadcasting = !broadcasting;
        syncBroadcastUI();

        if (broadcasting) {
            broadcastStart = Date.now();
            startBroadcastTimer();
            startGPS(vehicleId, userId, csrfToken);
        } else {
            stopGPS();
            stopBroadcastTimer();
        }
    });
}

/**
 * Live duration counter shown in the status text while broadcasting.
 * Ticks every second: "Broadcasting · 2m 14s"
 */
function startBroadcastTimer() {
    stopBroadcastTimer();  // clear any stale interval
    broadcastTimer = setInterval(() => {
        const elapsed = Math.floor((Date.now() - broadcastStart) / 1000);
        const m = Math.floor(elapsed / 60);
        const s = elapsed % 60;
        const label = m > 0
            ? `Broadcasting · ${m}m ${s.toString().padStart(2, '0')}s`
            : `Broadcasting · ${s}s`;
        setGpsStatusText(label);
    }, 1000);
}

function stopBroadcastTimer() {
    if (broadcastTimer !== null) {
        clearInterval(broadcastTimer);
        broadcastTimer = null;
    }
}

// ─── Bus occupancy — scale bounce ─────────────────────────────────────────────

function bindBusStatusButton() {
    const btn = document.getElementById('busFullBtn');

    btn?.addEventListener('click', async () => {
        if (!shiftActive) { alert('Start your shift first.'); return; }

        const isFull = btn.classList.contains('btnFull');

        try {
            const res = await fetch('/api/vehicles/occupancy', {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ is_full: !isFull }),
            });

            const data = await res.json();

            if (res.ok) {
                bounceBusBtn(btn);
                updateBusUI(data.is_full);
            }
        } catch (err) {
            console.error('Occupancy update failed:', err);
        }
    });
}

/**
 * Quick scale bounce: shrink → overshoot → settle.
 */
function bounceBusBtn(btn) {
    btn.style.transition = 'transform 80ms ease';
    btn.style.transform  = 'scale(0.92)';
    setTimeout(() => {
        btn.style.transform = 'scale(1.06)';
        setTimeout(() => {
            btn.style.transform = 'scale(1)';
            setTimeout(() => { btn.style.transition = ''; }, 100);
        }, 90);
    }, 80);
}

// ─── GPS logic ────────────────────────────────────────────────────────────────

function startGPS(vehicleId, userId, csrfToken) {
    if (!navigator.geolocation) {
        alert('GPS not supported by this browser.');
        return;
    }

    watchId = navigator.geolocation.watchPosition(
        (position) => {
            const now       = Date.now();
            const latitude  = position.coords.latitude;
            const longitude = position.coords.longitude;
            const speed     = position.coords.speed ?? 0;

            // Primary: whisper (ultra-low latency)
            channel.whisper('location.update', {
                vehicle_id: vehicleId,
                driver_id:  window.authUserId,
                latitude, longitude, speed,
                timestamp: now,
            });

            // Fallback: HTTP every 5 s (persists to DB)
            if (now - lastServerSync > SERVER_INTERVAL) {
                lastServerSync = now;
                fetch('/api/gps/update', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ vehicle_id: vehicleId, latitude, longitude, speed }),
                }).catch(err => console.warn('HTTP sync failed:', err));
            }

            // Mirror the 3-tier status logic from Vehicle::getGpsStatusAttribute()
            // and tracking.js so the strip always agrees with what students see.
            //   ≥ 3   → moving   (clearly rolling)
            //   ≥ 0.5 → traffic  (slow queue / red light — moved recently)
            //   < 0.5 → idle     (stationary — waiting for passengers)
            const derivedStatus = speed >= 3   ? 'moving'
                                : speed >= 0.5 ? 'traffic'
                                :                'idle';
            updateStickyStrip(derivedStatus);
        },
        (error) => { console.error('GPS error:', error); setGpsStatusText('GPS Error'); },
        { enableHighAccuracy: true, maximumAge: 1000, timeout: 20000 }
    );
}

function stopGPS() {
    if (watchId !== null) { navigator.geolocation.clearWatch(watchId); watchId = null; }
    setGpsStatusText('GPS Paused');
    updateStickyStrip('paused');
}

// ─── Sticky strip ─────────────────────────────────────────────────────────────

/**
 * Update the always-visible top strip with the current shift dot + GPS status.
 * gpsStatusHint: 'moving' | 'traffic' | 'idle' | 'paused' | undefined (just refresh dot)
 */
function updateStickyStrip(gpsStatusHint) {
    const strip = document.getElementById('stickyStrip');
    if (!strip) return;

    const stripDot    = document.getElementById('stripDot');
    const stripStatus = document.getElementById('stripStatus');

    // Dot colour mirrors the shift dot
    if (stripDot) {
        stripDot.className = 'strip-dot ' + (shiftActive ? 'strip-dot-active' : 'strip-dot-ended');
    }

    if (stripStatus) {
        if (!shiftActive) {
            stripStatus.textContent = 'Off duty';
        } else if (!broadcasting) {
            stripStatus.textContent = 'Shift active · GPS off';
        } else if (gpsStatusHint === 'moving') {
            stripStatus.textContent = 'Broadcasting · Moving';
        } else if (gpsStatusHint === 'traffic') {
            stripStatus.textContent = 'Broadcasting · In traffic';
        } else if (gpsStatusHint === 'idle') {
            stripStatus.textContent = 'Broadcasting · Idle';
        } else if (gpsStatusHint === 'paused') {
            stripStatus.textContent = 'Shift active · GPS paused';
        } else {
            stripStatus.textContent = 'Shift active';
        }
    }
}

// ─── UI sync helpers ──────────────────────────────────────────────────────────

function syncShiftUI() {
    const startBtn     = document.getElementById('startShiftBtn');
    const endBtn       = document.getElementById('endShiftBtn');
    const broadcastBtn = document.getElementById('broadcastBtn');

    if (shiftActive) {
        startBtn && (startBtn.style.display = 'none');
        endBtn   && (endBtn.style.display   = 'block');
        endBtn   && (endBtn.disabled        = false);
        endBtn   && (endBtn.textContent     = 'End Shift');
        if (broadcastBtn) { broadcastBtn.disabled = false; }
    } else {
        startBtn && (startBtn.style.display = 'block');
        startBtn && (startBtn.disabled      = false);
        startBtn && (startBtn.textContent   = 'Start Shift');
        endBtn   && (endBtn.style.display   = 'none');
        if (broadcastBtn) {
            broadcastBtn.disabled    = true;
            broadcastBtn.textContent = 'Start Shift First';
            broadcastBtn.classList.remove('btnActive');
            broadcastBtn.classList.add('btnInactive');
        }
    }
    updateShiftDot();
}

function syncBroadcastUI() {
    const btn = document.getElementById('broadcastBtn');
    if (!btn) return;

    if (broadcasting) {
        btn.textContent = 'Stop Broadcasting';
        btn.classList.replace('btnInactive', 'btnActive');
        btn.classList.add('btn-recording');         // pulsing ring (CSS animation)
    } else {
        btn.textContent = 'Start Broadcasting';
        btn.classList.replace('btnActive', 'btnInactive');
        btn.classList.remove('btn-recording');
        setGpsStatusText('Offline (GPS Paused)');
    }
}

// ─── Card pulse (border flash) ────────────────────────────────────────────────

/**
 * Briefly flash a coloured border ring on a card to confirm an action.
 * type: 'success' (green) | 'danger' (red)
 */
function pulseCard(cardClass, type) {
    const card = document.querySelector(`.${cardClass}`);
    if (!card) return;

    const cls = `card-pulse-${type}`;
    card.classList.add(cls);
    setTimeout(() => card.classList.remove(cls), 700);
}

// ─── Spinner helper ───────────────────────────────────────────────────────────

/**
 * Toggle a loading spinner inside a button.
 * When loading = true, replaces text with spinner + label and disables the button.
 */
function setSpinner(btn, loading, loadingText = 'Loading…') {
    if (!btn) return;
    if (loading) {
        btn.dataset.originalText = btn.textContent;
        btn.innerHTML = `<span class="btn-spinner"></span>${loadingText}`;
        btn.disabled  = true;
        btn.style.opacity = '0.8';
    } else {
        btn.textContent = btn.dataset.originalText ?? loadingText;
        btn.disabled    = false;
        btn.style.opacity = '';
    }
}

// ─── Misc UI helpers ──────────────────────────────────────────────────────────

function setGpsStatusText(text) {
    const el = document.getElementById('statusText');
    if (el) el.textContent = text;
}

function setShiftStatusText(text) {
    const el = document.getElementById('shiftStatusText');
    if (el) el.textContent = text;
}

function updateShiftDot() {
    const dot = document.getElementById('shiftDot');
    if (!dot) return;
    dot.classList.remove('active', 'ended');
    dot.classList.add(shiftActive ? 'active' : 'ended');
}

function updateBusUI(isFull) {
    const btn = document.getElementById('busFullBtn');
    if (!btn) return;
    if (isFull) {
        btn.textContent = 'Bus Status: FULL';
        btn.classList.remove('btnAvailable');
        btn.classList.add('btnFull');
    } else {
        btn.textContent = 'Bus Status: AVAILABLE';
        btn.classList.remove('btnFull');
        btn.classList.add('btnAvailable');
    }
}
