@extends('layouts.app')

@section('content')

<div class="fullScreen">

    <p class="pageLabelDark">TRACKING LOCATION</p>

    <!-- MAP CONTAINER -->
    <div class="mapContainer">
        <div id="map" style="width:100%; height:100%; border-radius:10px;"></div>
    </div>

    <!-- BACK BUTTON -->
    <a href="/student" class="primaryButtonWide">
        Back to List
    </a>

    <!-- LOGOUT -->
    <a href="/" class="backButton">
        Logout
    </a>

</div>

<script>
const vehicleId = {{ $jeepId }};
</script>

<script>
let map;
let jeepMarker = null;
let channel = null;
const expectedDriverId = {{ $vehicle->user_id }};

window.onload = function () {

    const vehicleId = {{ $jeepId }};

    map = L.map('map');

    map.setView([16.050889, 120.341236], 15);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap'
    }).addTo(map);


    // INITIAL LOAD
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

    setTimeout(() => {
        map.invalidateSize();
    }, 500);

};

window.addEventListener("load", function(){

    channel = Echo.private(`vehicle.${vehicleId}`);
    
    // Only use if whisper hasn't updated recently
    channel.listen('.location.updated', (event) => {
        const now = Date.now();

        if (now - lastTimestamp > 2000) {
            console.log("Using broadcast fallback");

            updateMarker(event.latitude, event.longitude);
        }

    });

   channel.listenForWhisper('location.update', (data) => {

        if (data.driver_id !== expectedDriverId) return;

        // ORDER CONTROL
        if (data.timestamp <= lastTimestamp) return;

        lastTimestamp = data.timestamp;

        const latency = Date.now() - data.timestamp;
        console.log("Whisper latency:", latency, "ms");

        updateMarker(data.latitude, data.longitude);

    });
});
    
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
</script>

@endsection