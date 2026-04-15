let lastTimestamp = 0;
let map;
let jeepMarker = null;
let channel = null;

export function initTracking(){
    const container = document.getElementById("mapContainer");
    if (!container) return;

    if (typeof L === "undefined") {
        console.error("Leaflet (L) is not loaded");
        return;
    }

    const vehicleId = container.dataset.vehicleId;
    const expectedDriverId = parseInt(container.dataset.driverId);

    initMap();
    loadInitialVehicle(vehicleId);
    initRealtime(vehicleId, expectedDriverId);
}

function initMap(){
     map = L.map('map');

    map.setView([16.050889, 120.341236], 15);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap'
    }).addTo(map);

    setTimeout(() => map.invalidateSize(), 500);
}

function loadInitialVehicle(vehicleId){
    fetch(`/api/vehicles/${vehicleId}`)
    .then(res => res.json())
    .then(vehicle => {

        if (!vehicle || !vehicle.latitude) {
            console.log("No vehicle data");
            return;
        }

        const lat = vehicle.latitude;
        const lng = vehicle.longitude;

        jeepMarker = L.marker([lat, lng]).addTo(map);
        map.setView([lat, lng], 16);
    });
}

function initRealtime(vehicleId, expectedDriverId){
    channel = Echo.private(`vehicle.${vehicleId}`);
    
    // Broadcast fallback
    channel.listen('.location.updated', (event) => {
        const now = Date.now();

        if (now - lastTimestamp > 2000) {
            console.log("Using broadcast fallback");

            updateMarker(event.latitude, event.longitude);
        }

    });
    //Whisper (primary)
   channel.listenForWhisper('location.update', (data) => {
        console.log("WHISPER RECEIVED:", data);

        if (data.driver_id !== expectedDriverId) return;

        // ORDER CONTROL
        if (data.timestamp <= lastTimestamp) return;

        lastTimestamp = data.timestamp;

        const latency = Date.now() - data.timestamp;
        console.log("Whisper latency:", latency, "ms");

        updateMarker(data.latitude, data.longitude);

    });
}

function updateMarker(lat, lng) {
    if (!jeepMarker) return;

    const current = jeepMarker.getLatLng();
    const target = L.latLng(lat, lng);

    const steps = 5;
    let i = 0;

    const interval = setInterval(() => {
        i++;

        const newLat = current.lat + (target.lat - current.lat) * (i / steps);
        const newLng = current.lng + (target.lng - current.lng) * (i / steps);

        jeepMarker.setLatLng([newLat, newLng]);

        if (i >= steps) {
            clearInterval(interval);
        }
    }, 50);

    map.panTo([lat, lng]);
}
