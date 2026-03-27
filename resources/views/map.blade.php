<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tracker</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
     integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
     crossorigin=""/>
     <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
     integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
     crossorigin=""></script>

    <style>
    #map { height: 400px; }
    </style>
@vite(['resources/js/app.js'])
</head>
<body>
    <h1>Vehicle Tracking</h1>
     <div id="map"></div>
     <button onclick="resetMarker()">create marker</button>
</body>
<script>
var map = L.map('map').setView([16.051011533751666, 120.3407345106514], 13);
window.map = map;
L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="http://www.openstreetmap.org/copyright">OpenStreetMap</a>'
}).addTo(map);

var marker = L.marker([16.051011533751666, 120.3407345106514]).addTo(map);

function resetMarker(){
    marker.setLatLng([16.051021533751666, 120.3407345106514]);
    map.panTo([16.051021533751666, 120.3407345106514]);
}

function moveMarker(lat, long){
    marker.setLatLng([lat, long]);
    map.panTo([lat, long]);
}
window.marker = marker;
</script>
</html>