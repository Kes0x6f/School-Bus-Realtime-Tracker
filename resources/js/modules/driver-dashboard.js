let broadcasting = false;
let watchId = null;
let channel = null;
let channelReady = false;
let lastServerSync = 0;

const SERVER_INTERVAL = 5000;

export function initDriverDashboard(){
    const app = document.getElementById("app");
    if(!app)return

    if (!window.Echo) {
        console.error("Echo not loaded");
        return;
    }

    const vehicleId = app.dataset.vehicleId;
    const userId = app.dataset.userId;
    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    setupChannel(vehicleId);
    broadcastToggle(vehicleId, userId, csrfToken);
    busStatusButton();
}

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
//broadcast to channel
function broadcastToggle(vehicleId, userId, csrfToken){
    const btn = document.getElementById("broadcastBtn");
    const statusText = document.getElementById("statusText");

    btn.addEventListener("click", () => {
        broadcasting = !broadcasting;

        if (broadcasting) {

            if (!channelReady) {
                console.warn("Channel not ready yet...");
                document.getElementById("statusText").innerText = "Connecting...";
                return;
            }

            btn.innerText = "Status: ACTIVE (Broadcasting)";
            btn.classList.remove("btnInactive");
            btn.classList.add("btnActive");

            document.getElementById("statusText").innerText = "Broadcasting - GPS Active";

            startGPS(vehicleId, userId, csrfToken);

        } else {

            btn.innerText = "Status: INACTIVE";
            btn.classList.remove("btnActive");
            btn.classList.add("btnInactive");

            document.getElementById("statusText").innerText = "Offline";

            stopGPS();
        }
    })
}
//occupancy status
function busStatusButton(){
    const btn = document.getElementById("busFullBtn");
    let isFull = false;

    btn.addEventListener("click", () =>{
        if (!broadcasting) return;

        isFull = !isFull;

        if (isFull) {
            btn.innerText = "Bus Status: FULL";
            btn.classList.remove("btnAvailable");
            btn.classList.add("btnFull");
        } else {
            btn.innerText = "Bus Status: AVAILABLE";
            btn.classList.remove("btnFull");
            btn.classList.add("btnAvailable");
        }
    })
}

//GPS logic
function startGPS(vehicleId, userId, csrfToken){
    if(!navigator.geolocation){
        alert("GPS not supported by browser");
        return;
    }

    watchId = navigator.geolocation.watchPosition(function(position){

        const now = Date.now();

        const latitude = position.coords.latitude;
        const longitude = position.coords.longitude;
        const speed = position.coords.speed || 0;

        channel.whisper('location.update', {
            vehicle_id: vehicleId,
            driver_id: window.authUserId,
            latitude,
            longitude,
            speed,
            timestamp: now
        });

        if(now - lastServerSync > SERVER_INTERVAL){
        lastServerSync = now;
        const route = document.getElementById("routeSelect")?.value;
            fetch('/api/gps/update', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({
                    vehicle_id: vehicleId,
                    latitude: latitude,
                    longitude: longitude,
                    speed: speed,
                    route_name: route
                })
            });
        }

    },
    function(error){
        console.error("GPS error:", error);
        document.getElementById("statusText").innerText = "GPS Error";
    },
    {
        enableHighAccuracy: true,
        maximumAge: 1000,
        timeout: 20000
    });

}

function stopGPS(){
    if(watchId !== null){
        navigator.geolocation.clearWatch(watchId);
    }
}
