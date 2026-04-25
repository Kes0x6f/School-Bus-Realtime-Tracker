@extends('layouts.app')

@section('content')

<!--
     TRACKING PAGE
     data-* attributes carry server-side state into tracking.js so the
     JS can render the correct initial UI without a second API round-trip.
-->

<div class="fullScreen"
    id="app"
    data-page="tracking"
    data-vehicle-id="{{ $jeepId }}"
    data-driver-id="{{ $vehicle->user_id }}"
    data-gps-status="{{ $vehicle->gps_status }}"
    data-last-seen="{{ $vehicle->last_seen?->toISOString() }}"
    data-speed="{{ $vehicle->speed ?? 0 }}"
    data-route="{{ $vehicle->route_name ?? '' }}"
    data-driver-name="{{ $vehicle->user->name ?? 'Unknown' }}"
    data-shift-started="{{ $vehicle->shift_started_at?->toISOString() }}"
    data-is-full="{{ $vehicle->is_full ? '1' : '0' }}"
    data-plate="{{ $vehicle->plate_number ?? '' }}">

    <p class="pageLabelDark">TRACKING LOCATION</p>

    <!-- MAP CONTAINER -->
    <div id="mapContainer" class="mapContainer" style="position: relative;">
        <div id="map" style="width:100%; height:100%; border-radius:10px;"></div>
        <! -- Floating Status Pill -->
        <div id="statusPill" class="map-status-pill">
            <span id="statusPillDot" class="pill-dot"></span>
            <span id="statusPillText">Loading…</span>
        </div>

        <! --  =====================================================================
        OVERLAYS & BANNERS
        All hidden by default; tracking.js controls visibility.
        ===================================================================== -->
    
        <! --  No-signal banner (sticky, bottom of map) -->
        <div id="noSignalBanner" class="tracking-banner tracking-banner-danger hidden">
            <span id="noSignalBannerText">⚠ No GPS signal</span>
        </div>
        
        <! --  Idle banner -->
        <div id="idleBanner" class="tracking-banner tracking-banner-warning hidden">
            🚌 Vehicle is currently stopped or idling
        </div>
        
        <! --  Toast notification stack -->
        <div id="toastStack" class="toast-stack" aria-live="polite"></div>
        
        <! --  Shift-ended full-screen modal -->
        <div id="shiftEndedOverlay" class="tracking-overlay hidden" role="dialog" aria-modal="true">
            <div class="shift-ended-card">
                <div class="shift-ended-icon">🚌</div>
                <h2 class="shift-ended-title">Shift Ended</h2>
                <p class="shift-ended-body">
                    This jeepney has finished its shift and is no longer active.
                </p>
                <a href="/student/active-jeeps" class="shift-ended-btn-primary">
                    View Active Jeepneys
                </a>
                <button id="shiftEndedDismiss" class="shift-ended-btn-secondary">
                    Stay on this page
                </button>
            </div>
        </div>
    </div>

    <!-- Vechicle Info -->
    <div id="infoPanel" class="tracking-info-panel">
 
        <! --  Row 1: Route + Capacity -->
        <div class="info-row info-row-space">
            <div>
                <p class="info-label">ROUTE</p>
                <p id="infoRoute" class="info-value info-value-lg">
                    {{ $vehicle->route_name ?? 'N/A' }}
                </p>
            </div>
            <div id="infoCapacityBadge" class="capacity-badge {{ $vehicle->is_full ? 'cap-full' : 'cap-available' }}">
                {{ $vehicle->is_full ? 'FULL' : 'SEATS AVAILABLE' }}
            </div>
        </div>
 
        <! --  Row 2: Driver -->
        <div class="info-col">
            <p class="info-label">DRIVER</p>
            <p id="infoDriver" class="info-value">
                {{ $vehicle->user->name ?? 'Unknown' }}
            </p>
        </div>
 
        <! --  Row 3: Speed / Last update / Shift started -->
        <div class="info-grid">
            <div class="info-col">
                <p class="info-label">SPEED</p>
                <p id="infoSpeed" class="info-value">
                    {{ $vehicle->speed !== null ? round($vehicle->speed) . ' km/h' : '-- km/h' }}
                </p>
            </div>
            <div class="info-col">
                <p class="info-label">LAST UPDATE</p>
                <p id="infoLastSeen" class="info-value">--</p>
            </div>
            <div class="info-col">
                <p class="info-label">SHIFT STARTED</p>
                <p id="infoShiftStart" class="info-value">
                    @if($vehicle->shift_started_at)
                        {{ $vehicle->shift_started_at->format('g:i A') }}
                    @else
                        --
                    @endif
                </p>
            </div>
        </div>
 
    </div>

    

    <!-- BACK BUTTON -->
    <a href="/student/active-jeeps" class="primaryButtonWide" style="text-align:center; display:block; text-decoration:none;">
        ← Back to List
    </a>
    <a href="/" class="backButton" style="text-align:center; display:block; text-decoration:none; margin-top:8px;">
        Logout
    </a>

</div>


@endsection