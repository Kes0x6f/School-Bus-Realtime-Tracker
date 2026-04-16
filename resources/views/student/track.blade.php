@extends('layouts.app')

@section('content')

<div class="fullScreen"
id="app"
data-page="tracking">

    <p class="pageLabelDark">TRACKING LOCATION</p>

    <!-- MAP CONTAINER -->
    <div id="mapContainer" 
        data-vehicle-id="{{ $jeepId }}"
        data-driver-id="{{ $vehicle->user_id }}"
        class="mapContainer">
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

<div id="noSignalBanner" class="trackingBanner hidden"></div>
<div id="idleBanner" class="trackingBanner hidden"></div>
<div id="shiftEndedOverlay" class="trackingOverlay hidden"></div>

@endsection