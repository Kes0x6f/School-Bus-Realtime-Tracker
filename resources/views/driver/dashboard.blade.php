@extends('layouts.app')

@section('content')

@php($routes = \App\Enums\VehicleRoute::cases())
<div id="app"
     class="driver-page"
     data-page="driver-dashboard"
     data-vehicle-id="{{ $vehicleId }}"
     data-user-id="{{ auth()->id() }}"
     data-shift-active="{{ $vehicle->shift_active ? '1' : '0' }}"
     data-is-full="{{ $vehicle->is_full ? '1' : '0' }}">

    {{-- ── FIXED HEADER (single navy bar — no white strip above it) ── --}}
    <header class="driver-mobile-header">
        <div class="driver-top-bar">
            <span class="driver-top-plate">{{ $vehicle->plate_number }}</span>

            <div id="stickyStrip" class="sticky-strip-inline">
                <span id="stripDot"
                      class="strip-dot {{ $vehicle->shift_active ? 'strip-dot-active' : 'strip-dot-ended' }}">
                </span>
                <span id="stripStatus" class="strip-status">
                    {{ $vehicle->shift_active ? 'Shift active' : 'Off duty' }}
                </span>
            </div>

            <form method="POST" action="/logout" style="margin:0;">
                @csrf
                <button class="driver-logout-btn" type="submit">Sign out</button>
            </form>
        </div>
    </header>

    <main class="dash-wrap">

        {{-- ── SHIFT CARD ── --}}
        <div class="shift-card slide-in">
            <p class="shift-card-header">Shift Status</p>

            <div class="shift-status-row">
                <span id="shiftDot" class="shift-dot {{ $vehicle->shift_active ? 'active' : 'ended' }}"></span>
                <span id="shiftStatusText">{{ $vehicle->shift_active ? 'Shift Active' : 'Off Duty' }}</span>
            </div>

            <div class="shift-actions">
                <button id="startShiftBtn" style="{{ $vehicle->shift_active ? 'display:none;' : '' }}">
                    Start Shift
                </button>
                <button id="endShiftBtn" style="{{ !$vehicle->shift_active ? 'display:none;' : '' }}">
                    End Shift
                </button>
            </div>
        </div>

        {{-- ── ROUTE CARD ── --}}
        <div class="section-card slide-in">
            <p class="section-label">Route</p>

            <select id="routeSelect" class="input">
                <option value="">— Select route —</option>
                @foreach($routes as $route)
                    <option value="{{ $route->value }}" {{ $vehicle->route_name === $route->value ? 'selected' : '' }}>
                        {{ $route->value }}
                    </option>
                @endforeach
            </select>
            {{-- JS inserts #routeSavedHint after the select --}}
        </div>

        {{-- ── GPS BROADCAST CARD ── --}}
        <div class="section-card slide-in">
            <p class="section-label">GPS Broadcast</p>

            <div class="gps-status-row">
                <div class="gps-status-icon">📡</div>
                <div>
                    <p class="gps-status-meta">Signal status</p>
                    <p id="statusText" class="gps-status-text">
                        {{ $vehicle->shift_active ? 'Ready to broadcast' : 'Offline' }}
                    </p>
                </div>
            </div>

            <button id="broadcastBtn"
                    class="{{ $vehicle->shift_active ? 'btnInactive' : '' }}"
                    {{ !$vehicle->shift_active ? 'disabled' : '' }}>
                {{ $vehicle->shift_active ? 'Start Broadcasting' : 'Start Shift First' }}
            </button>
        </div>

        {{-- ── OCCUPANCY CARD ── --}}
        <div class="section-card slide-in">
            <p class="section-label">Passenger Capacity</p>

            <button id="busFullBtn"
                    class="{{ $vehicle->is_full ? 'btnFull' : 'btnAvailable' }}">
                Bus Status: {{ $vehicle->is_full ? 'FULL' : 'AVAILABLE' }}
            </button>

            <p class="capacity-hint">Tap to update passenger capacity</p>
        </div>

    </main>
</div>
@endsection
