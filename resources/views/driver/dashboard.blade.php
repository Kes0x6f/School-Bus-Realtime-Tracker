@extends('layouts.app')

@section('content')
<div id="app"
     class="driver-page"
     data-page="driver-dashboard"
     data-vehicle-id="{{ $vehicleId }}"
     data-user-id="{{ auth()->id() }}"
     data-shift-active="{{ $vehicle->shift_active ? '1' : '0' }}"
     data-is-full="{{ $vehicle->is_full ? '1' : '0' }}">

    <!-- MOBILE HEADER -->
    <header class="driver-mobile-header">
        <div class="driver-top-white"></div>
        <div class="driver-top-blue"></div>

        <div id="stickyStrip" class="sticky-strip">
            <div class="sticky-strip-inner">
                <span class="strip-plate">{{ $vehicle->plate_number }}</span>

                <span class="strip-info">
                    <span id="stripDot"
                          class="strip-dot {{ $vehicle->shift_active ? 'strip-dot-active' : 'strip-dot-ended' }}">
                    </span>

                    <span id="stripStatus" class="strip-status">
                        {{ $vehicle->shift_active ? 'Shift active' : 'Off duty' }}
                    </span>
                </span>
            </div>
        </div>
    </header>

    <main class="dash-wrap">

        <!-- HEADER -->
        <div class="dash-header slide-in">
            <div>
                <p class="dash-header-label">Operations Control</p>
                <h1 class="dash-header-title">Driver Dashboard</h1>
            </div>

            <span class="dash-plate">{{ $vehicle->plate_number }}</span>
        </div>

        <!-- SHIFT -->
        <div class="shift-card slide-in">
            <p class="shift-card-header">Shift Status</p>

            <div class="shift-status-row">
                <span id="shiftDot" class="shift-dot"></span>
                <span id="shiftStatusText"></span>
            </div>

            <div class="shift-actions">
                <button id="startShiftBtn">Start Shift</button>
                <button id="endShiftBtn">End Shift</button>
            </div>
        </div>

        <!-- ROUTE -->
        <div class="section-card slide-in">
            <p class="section-label">Route</p>

            <select id="routeSelect" class="input">
                <option value="Route A – Mangaldan"
                    {{ $vehicle->route_name === 'Route A – Mangaldan' ? 'selected' : '' }}>
                    Route A – Mangaldan
                </option>

                <option value="Route B – Calasiao"
                    {{ $vehicle->route_name === 'Route B – Calasiao' ? 'selected' : '' }}>
                    Route B – Calasiao
                </option>

                <option value="Route C – San Fabian"
                    {{ $vehicle->route_name === 'Route C – San Fabian' ? 'selected' : '' }}>
                    Route C – San Fabian
                </option>
            </select>
        </div>

        <!-- BROADCAST -->
        <div class="section-card slide-in">
            <p class="section-label">GPS Broadcast</p>
            <button id="broadcastBtn">Start Broadcasting</button>
            <p id="statusText">Offline</p>
        </div>

        <!-- CAPACITY -->
        <div class="section-card slide-in">
            <p class="section-label">Passenger Capacity</p>

            <button id="busFullBtn"
                    class="{{ $vehicle->is_full ? 'btnFull' : 'btnAvailable' }}">
                Bus Status: {{ $vehicle->is_full ? 'FULL' : 'AVAILABLE' }}
            </button>
        </div>

        <!-- FOOTER -->
        <div class="dash-footer slide-in">
            <form method="POST" action="/logout">
                @csrf
                <button class="logout-btn">Logout</button>
            </form>
        </div>

    </main>
</div>
@endsection