@extends('layouts.app')

@section('content')
<div class="p-4 max-w-md mx-auto space-y-6">

    <div class="flex justify-end">
        <a href="/" 
             class="block text-center bg-red-500 text-white p-2 rounded-xl">
             Logout
    </a>
    </div>

    <h2 class="text-xl font-bold text-center">Driver Panel</h2>

    <select class="w-full p-3 rounded-xl border">
        <option>Route A - Mangaldan</option>
        <option>Route B - Calasiao</option>
        <option>Route C - San Fabian</option>
    </select>

    <button id="broadcastBtn"
        class="w-full bg-green-600 text-white p-4 rounded-2xl">
        Start Broadcasting
    </button>

    <button id="busFullBtn"
        class="w-full bg-yellow-500 text-white p-4 rounded-2xl">
        Mark as Full
    </button>

    <div class="text-center">
        Status: <span id="statusText">Offline</span>
    </div>

</div>

<script>
let broadcasting = false;
let watchId = null;
let lastUpdate = 0;

const vehicleId = 1; // later this should come from logged-in driver

const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

document.getElementById("statusText").innerText =
"Broadcasting - GPS Active";

document.getElementById("broadcastBtn").addEventListener("click", function(){

    broadcasting = !broadcasting;

    if (broadcasting) {

        this.innerText = "Stop Broadcasting";
        this.classList.replace("bg-green-600", "bg-red-600");
        document.getElementById("statusText").innerText = "Broadcasting";

        startGPS();

    } else {

        this.innerText = "Start Broadcasting";
        this.classList.replace("bg-red-600", "bg-green-600");
        document.getElementById("statusText").innerText = "Offline";

        stopGPS();
    }

});

function startGPS(){

    if(!navigator.geolocation){
        alert("GPS not supported by browser");
        return;
    }

    watchId = navigator.geolocation.watchPosition(function(position){

        const now = Date.now();

        // limit updates to every 3 seconds
        if(now - lastUpdate < 3000){
            return;
        }

        lastUpdate = now;

        const latitude = position.coords.latitude;
        const longitude = position.coords.longitude;
        const speed = position.coords.speed || 0;

        console.log("Sending GPS:", latitude, longitude);

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
                speed: speed
            })

        });

    },
    function(error){

        console.error("GPS error:", error);
        document.getElementById("statusText").innerText = "GPS Error";

    },
    {
        enableHighAccuracy: true,
        maximumAge: 0,
        timeout: 5000
    });

}

function stopGPS(){

    if(watchId !== null){
        navigator.geolocation.clearWatch(watchId);
    }

}
</script>

@endsection