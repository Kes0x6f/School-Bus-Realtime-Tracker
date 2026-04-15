@extends('layouts.app')

@section('content')

<div class="container" 
    style="background-image: url('{{ asset('images/bgg.png') }}');"
     id="app"
     data-page="driver-dashboard"
     data-vehicle-id="{{ $vehicleId }}"
     data-user-id="{{ auth()->id() }}"
>

    <div class="card slideIn">

        <p class="pageLabel">OPERATIONS CONTROL</p>
        <p class="title">Driver Dashboard</p>

        <!-- ROUTE SELECT -->
        <div class="routeBox">
            <p class="routeHeader">Select Route:</p>
            <select id="routeSelect" class="input">
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

@endsection