const map = L.map('map').setView([16.051011533751666, 120.3407345106514], 13);

L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="http://www.openstreetmap.org/copyright">OpenStreetMap</a>',
}).addTo(map);

const marker = L.marker([16.051011533751666, 120.3407345106514]).addTo(map);

document.getElementById('resetMarkerButton')?.addEventListener('click', () => {
    marker.setLatLng([16.051021533751666, 120.3407345106514]);
    map.panTo([16.051021533751666, 120.3407345106514]);
});
