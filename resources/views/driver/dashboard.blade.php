@extends('layouts.app')

@section('content')

<div class="container" style="background-image: url('{{ asset('images/bgg.png') }}');">

    <div class="card slideIn">

        <p class="pageLabel">OPERATIONS CONTROL</p>
        <p class="title">Driver Dashboard</p>

        <!-- ROUTE SELECT -->
        <div class="routeBox">
            <p class="routeHeader">Select Route:</p>
            <select class="input">
                <option>Route A - Mangaldan</option>
                <option>Route B - Calasiao</option>
                <option>Route C - San Fabian</option>
            </select>
        </div>

        <!-- BROADCAST BUTTON -->
        <button id="broadcastBtn"
            class="actionButton btnInactive">
            Status: INACTIVE
        </button>

        <!-- BUS STATUS -->
        <button id="busFullBtn"
            class="actionButton btnAvailable">
            Bus Status: AVAILABLE
        </button>

        <!-- STATUS TEXT -->
        <div style="margin-top:10px;">
            <p class="routeHeader">System Status:</p>
            <p id="statusText" class="routeText">Offline</p>
        </div>

        <!-- LOGOUT -->
        <button onclick="window.location.href='/'">
            <p class="backLink">End Shift & Log Out</p>
        </button>

    </div>

</div>

<script>
let broadcasting = false;
let watchId = null;
let lastUpdate = 0;
const SERVER_INTERVAL = 5000;
window.authUserId = {{ auth()->id() }};

const vehicleId = {{ $vehicleId }};
const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

// BROADCAST TOGGLE
document.getElementById("broadcastBtn").addEventListener("click", function(){

    broadcasting = !broadcasting;

    if (broadcasting) {

        this.innerText = "Status: ACTIVE (Broadcasting)";
        this.classList.remove("btnInactive");
        this.classList.add("btnActive");

        document.getElementById("statusText").innerText = "Broadcasting - GPS Active";

        startGPS();

    } else {

        this.innerText = "Status: INACTIVE";
        this.classList.remove("btnActive");
        this.classList.add("btnInactive");

        document.getElementById("statusText").innerText = "Offline";

        stopGPS();
    }

});

// BUS FULL TOGGLE
let isFull = false;

document.getElementById("busFullBtn").addEventListener("click", function(){

    if (!broadcasting) return;

    isFull = !isFull;

    if (isFull) {
        this.innerText = "Bus Status: FULL";
        this.classList.remove("btnAvailable");
        this.classList.add("btnFull");
    } else {
        this.innerText = "Bus Status: AVAILABLE";
        this.classList.remove("btnFull");
        this.classList.add("btnAvailable");
    }

});

let channel = null;

window.addEventListener("load", () => {
    if (!window.Echo) {
        console.error("Echo not loaded");
        return;
    }

    channel = window.Echo.private(`vehicle.${vehicleId}`);

    channel.subscribed(() => {
        console.log("Channel subscribed");
        channelReady = true;
    });

    channel.error((err) => {
        console.error("Channel error:", err);
    });
});

let lastServerSync = 0;

// GPS FUNCTION

if (!channelReady) {
    console.warn("Channel not ready yet...");
    document.getElementById("statusText").innerText = "Connecting...";
    return;
}

function startGPS(){


    if(!navigator.geolocation){
        alert("GPS not supported by browser");
        return;
    }

    watchId = navigator.geolocation.watchPosition(function(position){

        const now = Date.now();


        lastUpdate = now;

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
</script>

@endsection