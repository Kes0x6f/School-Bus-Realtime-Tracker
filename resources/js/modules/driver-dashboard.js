let broadcasting = false;
let watchId = null;
let channel = null;
let channelReady = false;
let shiftActive = false;
let lastServerSync = 0;

const SERVER_INTERVAL = 5000;

export function initDriverDashboard(){
    const app = document.getElementById("app");
    if(!app)return

    shiftActive = app.dataset.shiftActive === "1";
    syncShiftUI();

    if (!window.Echo) {
        console.error("Echo not loaded");
        return;
    }

    const vehicleId = app.dataset.vehicleId;
    const userId = app.dataset.userId;
    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    setupChannel(vehicleId);
    bindShiftButtons(vehicleId, csrfToken);
    bindBroadcastToggle(vehicleId, userId, csrfToken);
    bindBusStatusButton();
    updateShiftDot();
    setShiftStatusText(shiftActive ? "Shift Active" : "Off Duty");
    updateBusUI(app.dataset.isFull === "1");
}
//Channel
function setupChannel(vehicleId){
    channel = window.Echo.private(`vehicle.${vehicleId}`);

    channel.subscribed(() => {
        console.log("Channel subscribed");
        channelReady = true;
    });

    channel.error((err) => {
        console.error("Channel error:", err);
    });
}
//shift 
function bindShiftButtons(vehicleId, csrfToken){
    const startBtn = document.getElementById("startShiftBtn");
    const endBtn   = document.getElementById("endShiftBtn");

    startBtn?.addEventListener("click", async () => {
        startBtn.disabled = true;
        startBtn.textContent = "Starting...";
 
        try {
            const res = await fetch('/driver/shift/start', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
            });
 
            const data = await res.json();
            if (res.ok) {
                shiftActive = true;
                syncShiftUI();
                setShiftStatusText("Shift Active");
                console.log("Shift started:", data);
            } else {
                startBtn.disabled = false;
                startBtn.textContent = "Start Shift";
                alert(data.message ?? "Failed to start shift.");
            }
        } catch (err) {
            console.error("Shift start error:", err);
            startBtn.disabled = false;
            startBtn.textContent = "Start Shift";
        }
    });
 
    endBtn?.addEventListener("click", async () => {
        if (!confirm("End your shift? You will be removed from the active list.")) return;
 
        // Stop GPS before ending shift
        if (broadcasting) {
            stopGPS();
            broadcasting = false;
            syncBroadcastUI();
        }
 
        endBtn.disabled = true;
        endBtn.textContent = "Ending...";
 
        try {
            const res = await fetch('/driver/shift/end', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
            });
 
            const data = await res.json();
            
            if (res.ok) {
                shiftActive = false;
                syncShiftUI();
                setShiftStatusText("Shift Ended");
                setGpsStatusText("Offline");
                console.log("Shift ended:", data);
            } else {
                endBtn.disabled = false;
                endBtn.textContent = "End Shift";
                alert(data.message ?? "Failed to end shift.");
            }
        } catch (err) {
            console.error("Shift end error:", err);
            endBtn.disabled = false;
            endBtn.textContent = "End Shift";
        }
    });
}
// GPS broadcast toggle
function bindBroadcastToggle(vehicleId, userId, csrfToken) {
    const btn = document.getElementById("broadcastBtn");
 
    btn?.addEventListener("click", () => {
        if (!shiftActive) {
            alert("Start your shift first before broadcasting.");
            return;
        }
 
        if (!channelReady) {
            setGpsStatusText("Connecting...");
            return;
        }
 
        broadcasting = !broadcasting;
        syncBroadcastUI();
 
        if (broadcasting) {
            startGPS(vehicleId, userId, csrfToken);
        } else {
            stopGPS();
        }
    });
}

// Bus Occupancy
function bindBusStatusButton() {
    const btn = document.getElementById("busFullBtn");

    btn?.addEventListener("click", async () => {
        if (!shiftActive){
            alert("Start your shift first.");
            return;
        }

        const isFull = btn.classList.contains("btnFull");

        try {
            const res = await fetch('/api/vehicles/occupancy', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({
                    is_full: !isFull
                })
            });

            const data = await res.json();

            if (res.ok) {
                updateBusUI(data.is_full);
            }

        } catch (err) {
            console.error("Occupancy update failed:", err);
        }
    });
}

//GPS logic
function startGPS(vehicleId, userId, csrfToken) {
    if (!navigator.geolocation) {
        alert("GPS not supported by this browser.");
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
                latitude,
                longitude,
                speed,
                timestamp: now,
            });
 
            // Fallback: HTTP sync every 5s (persists to DB)
            if (now - lastServerSync > SERVER_INTERVAL) {
                lastServerSync = now;
 
                const route = document.getElementById("routeSelect")?.value;
 
                fetch('/api/gps/update', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({
                        vehicle_id: vehicleId,
                        latitude,
                        longitude,
                        speed,
                        route_name: route,
                    }),
                }).catch(err => console.warn("HTTP sync failed:", err));
            }
 
            // Update local status indicator
            const statusLabel = speed < 1 ? "Broadcasting — Idle" : "Broadcasting — Moving";
            setGpsStatusText(statusLabel);
        },
        (error) => {
            console.error("GPS error:", error);
            setGpsStatusText("GPS Error");
        },
        {
            enableHighAccuracy: true,
            maximumAge: 1000,
            timeout: 20000,
        }
    );
}

function stopGPS(){
    if(watchId !== null){
        navigator.geolocation.clearWatch(watchId);
        watchId = null;
    }
    setGpsStatusText("GPS Paused");
}

//UI helpers
/**
 * Sync shift-related UI to current shiftActive state.
 * - Shows/hides start and end shift buttons
 * - Enables/disables the broadcast button
 */
function syncShiftUI() {
    const startBtn     = document.getElementById("startShiftBtn");
    const endBtn       = document.getElementById("endShiftBtn");
    const broadcastBtn = document.getElementById("broadcastBtn");
 
    if (shiftActive) {
        startBtn && (startBtn.style.display = "none");
        endBtn   && (endBtn.style.display   = "block");
        endBtn   && (endBtn.disabled        = false);
        endBtn   && (endBtn.textContent     = "End Shift");
 
        if (broadcastBtn) {
            broadcastBtn.disabled = false;
        }
    } else {
        startBtn && (startBtn.style.display = "block");
        startBtn && (startBtn.disabled      = false);
        startBtn && (startBtn.textContent   = "Start Shift");
        endBtn   && (endBtn.style.display   = "none");
 
        if (broadcastBtn) {
            broadcastBtn.disabled = true;
            broadcastBtn.textContent = "Start Shift First";
            broadcastBtn.classList.remove("btnActive");
            broadcastBtn.classList.add("btnInactive");
        }
    }
    updateShiftDot();
}
 
/**
 * Sync broadcast button appearance to current broadcasting state.
 */
function syncBroadcastUI() {
    const btn = document.getElementById("broadcastBtn");
    if (!btn) return;
 
    if (broadcasting) {
        btn.textContent = "Stop Broadcasting";
        btn.classList.replace("btnInactive", "btnActive");
    } else {
        btn.textContent = "Start Broadcasting";
        btn.classList.replace("btnActive", "btnInactive");
        setGpsStatusText("Offline (GPS Paused)");
    }
}
 
function setGpsStatusText(text) {
    const el = document.getElementById("statusText");
    if (el) el.textContent = text;
}
 
function setShiftStatusText(text) {
    const el = document.getElementById("shiftStatusText");
    if (el) el.textContent = text;
}
 
function updateShiftDot() {
    const dot = document.getElementById("shiftDot");
    if (!dot) return;

    dot.classList.remove("active", "ended");

    if (shiftActive) {
        dot.classList.add("active");
    } else {
        dot.classList.add("ended");
    }
}

function updateBusUI(isFull) {
    const btn = document.getElementById("busFullBtn");

    if (!btn) return;

    if (isFull) {
        btn.textContent = "Bus Status: FULL";
        btn.classList.remove("btnAvailable");
        btn.classList.add("btnFull");
    } else {
        btn.textContent = "Bus Status: AVAILABLE";
        btn.classList.remove("btnFull");
        btn.classList.add("btnAvailable");
    }
}