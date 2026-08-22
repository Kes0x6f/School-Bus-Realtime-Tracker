@extends('layouts.app')

@section('content')

{{--
    TRACKING PAGE
    data-* attributes carry server-side state into tracking.js so the
    JS can render the correct initial UI without a second API round-trip.
--}}

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
    data-plate="{{ $vehicle->plate_number ?? '' }}"
    data-announcements='@json($announcements)'
    style="position:relative;">

    {{-- SETTINGS ICON — top right, subtle gear --}}
    <button
        data-open-student-settings
        aria-label="Account settings"
        style="position:absolute;
               top:16px;
               right:16px;
               width:36px;
               height:36px;
               border-radius:50%;
               border:none;
               background:rgba(0,0,0,0.06);
               cursor:pointer;
               display:flex;
               align-items:center;
               justify-content:center;
               padding:0;
               color:#555;
               z-index:10;">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18"
             viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="3"/>
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83
                     2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33
                     1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09
                     A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06
                     a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15
                     a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09
                     A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06
                     a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68
                     a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09
                     a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06
                     a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9
                     a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09
                     a1.65 1.65 0 0 0-1.51 1z"/>
        </svg>
    </button>

    <p class="pageLabelDark">TRACKING LOCATION</p>

    <!-- MAP CONTAINER -->
    <div id="mapContainer" class="mapContainer" style="position: relative;">
        <div id="map" style="width:100%; height:100%; border-radius:10px;"></div>

        <!-- Floating Status Pill -->
        <div id="statusPill" class="map-status-pill">
            <span id="statusPillDot" class="pill-dot"></span>
            <span id="statusPillText">Loading…</span>
        </div>

        <!-- No-signal banner -->
        <div id="noSignalBanner" class="tracking-banner tracking-banner-danger hidden">
            <span id="noSignalBannerText">⚠ No GPS signal</span>
        </div>

        <!-- Idle banner -->
        <div id="idleBanner" class="tracking-banner tracking-banner-warning hidden">
            🚌 Vehicle is currently stopped or idling
        </div>

        <!-- Toast notification stack -->
        <div id="toastStack" class="toast-stack" aria-live="polite"></div>

        <!-- Shift-ended full-screen modal -->
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

    <!-- Vehicle Info -->
    <div id="infoPanel" class="tracking-info-panel">

        <!-- Row 1: Route + Capacity -->
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

        <!-- Row 2: Driver -->
        <div class="info-col">
            <p class="info-label">DRIVER</p>
            <p id="infoDriver" class="info-value">
                {{ $vehicle->user->name ?? 'Unknown' }}
            </p>
        </div>

        <!-- Row 3: Speed / Last update / Shift started -->
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

        <!-- Row 4: Your Location (proximity section) -->
        {{--
            Separated by a hairline rule so it reads as a distinct "about you"
            section while staying visually inside the same card.
            infoDistance and infoDirection are written to by tracking.js.
            CSS classes live in app.css under "PROXIMITY SECTION".
        --}}
        <div class="proximity-section">
            <p class="info-label proximity-header">
                <span class="proximity-dot"></span>
                YOUR LOCATION
            </p>
            <div class="info-grid-2">
                <div class="info-col">
                    <p class="info-label">DISTANCE</p>
                    <p id="infoDistance" class="info-value">Locating…</p>
                </div>
                <div class="info-col">
                    <p class="info-label">DIRECTION TO JEEP</p>
                    <p id="infoDirection" class="info-value">—</p>
                </div>
            </div>
        </div>

    </div>

    <!-- BACK BUTTON -->
    <a href="/student/active-jeeps" class="primaryButtonWide"
       style="text-align:center; display:block; text-decoration:none;">
        ← Back to List
    </a>

    <!-- Logout -->
    <a href="/logout"
       data-logout-link
       style="display:block;text-align:center;margin-top:12px;
              font-size:13px;color:#999;text-decoration:none;padding:8px;">
        Logout
    </a>
    <form id="logout-form" method="POST" action="/logout" style="display:none;">
        @csrf
    </form>

    {{-- ACCOUNT SETTINGS MODAL --}}
    <div id="studentPwOverlay" class="modal-overlay hidden">
        <div class="modal-box">

            <div class="modal-header">
                <h2 class="modal-title">Account Settings</h2>
                <button class="modal-close"
                        data-close-student-settings>
                    ✕
                </button>
            </div>

            {{-- Contact admin notice --}}
            <div style="background:#F0F4FF;
                        border-left:3px solid #002D62;
                        border-radius:6px;
                        padding:10px 14px;
                        margin-bottom:20px;">
                <p style="font-size:12px;color:#002D62;margin:0;line-height:1.6;">
                    To update your <strong>name</strong> or <strong>email address</strong>,
                    please contact an administrator.
                </p>
            </div>

            {{-- Section label --}}
            <p style="font-size:11px;font-weight:600;color:#9CA3AF;
                      text-transform:uppercase;letter-spacing:0.06em;margin-bottom:10px;">
                Change Password
            </p>

            <div class="modal-form" id="studentPwForm">
                <label class="modal-label">Current password</label>
                <input class="modal-input" id="spwCurrent" type="password"
                       placeholder="Your current password" autocomplete="current-password">

                <label class="modal-label">New password</label>
                <input class="modal-input" id="spwNew" type="password"
                       placeholder="At least 8 characters" autocomplete="new-password">

                <label class="modal-label">Confirm new password</label>
                <input class="modal-input" id="spwConfirm" type="password"
                       placeholder="Repeat new password" autocomplete="new-password">

                <p id="spwHint" style="font-size:11px;color:#9CA3AF;min-height:16px;"></p>

                <p id="spwError"
                   style="font-size:12px;color:#DC2626;display:none;
                          background:#FEE2E2;padding:8px 12px;border-radius:6px;">
                </p>

                <button id="spwSubmitBtn"
                        style="background:#002D62;color:#fff;border:none;
                               padding:12px;border-radius:10px;font-size:14px;
                               font-weight:600;cursor:pointer;width:100%;
                               font-family:inherit;margin-top:4px;">
                    Update password
                </button>
            </div>

            <p id="spwSuccess"
               style="display:none;font-size:13px;font-weight:600;color:#065F46;
                      background:#D1FAE5;padding:12px;border-radius:8px;text-align:center;">
                Password updated successfully.
            </p>

        </div>
    </div>

</div>

@endsection
