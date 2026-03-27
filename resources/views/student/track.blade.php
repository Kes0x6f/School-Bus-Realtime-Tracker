@extends('layouts.app')

@section('content')
<div class="p-2 bg-white shadow flex justify-between items-center">
    <a href="/student" class="text-sm text-blue-600">← Back</a>
    <a href="/" 
    class="block text-center bg-red-500 text-white p-2 rounded-xl">
        Logout
    </a>
</div>

<div class="h-screen flex flex-col">
    <div id="map" class="flex-1"></div>
</div>
<script>
const vehicleId = {{ $jeepId }};
</script>
<script>
const map = L.map('map').setView([16.050889, 120.341236], 15);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap'
}).addTo(map);

let jeepMarker = null;

fetch(`/api/vehicles/${vehicleId}`)
.then(res => res.json())
.then(vehicle => {

    const lat = vehicle.latitude;
    const lng = vehicle.longitude;

    jeepMarker = L.marker([lat, lng]).addTo(map);

    map.setView([lat, lng], 16);

});

window.addEventListener("load", function(){
    Echo.channel(`vehicle.${vehicleId}`)
    .listen('.location.updated', (event) => {

        const lat = event.latitude;
        const lng = event.longitude;

        if (jeepMarker) {
            jeepMarker.setLatLng([lat, lng]);
            map.panTo([lat, lng]);
        }

    });
});
</script>

@endsection