@extends('layouts.app')

@section('content')

<div class="fullScreen slideIn"
id="app"
data-page="active-jeeps"
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
               color:#555;">
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

    <p class="pageLabelDark">UDD CAMPUS SHUTTLE SERVICE</p>
    <p class="headerTitle">Available E-Busses</p>

    {{-- ROUTE FILTER --}}
    <div style="width:100%;max-width:400px;margin:0 auto 12px;">
        <select id="routeFilter" style="
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid #ddd;
            border-radius: 12px;
            font-size: 14px;
            font-family: inherit;
            background: #fff;
            color: #1A1A1A;
            appearance: none;
            -webkit-appearance: none;
            background-image: url('data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%2212%22 height=%2212%22 viewBox=%220 0 12 12%22><path fill=%22%23666%22 d=%22M6 8L1 3h10z%22/></svg>');
            background-repeat: no-repeat;
            background-position: right 14px center;
            cursor: pointer;
        ">
            <option value="all">All routes</option>
            @foreach($jeeps->pluck('route_name')->filter()->unique()->sort()->values() as $route)
                <option value="{{ $route }}">{{ $route }}</option>
            @endforeach
        </select>
    </div>

    <div
        id="vehicleList"
        data-vehicle-ids='@json($jeeps->pluck("id"))'
        style="width: 100%; display: flex; flex-direction: column; align-items: center;"
    >
        @foreach($jeeps as $jeep)
            <a href="/student/track/{{ $jeep->id }}"
                class="jeepCard"
                data-vehicle-id="{{ $jeep->id }}"
                data-route="{{ $jeep->route_name }}">

                <p class="jeepRoute">Route: {{ $jeep->route_name }}</p>
                <p class="jeepDetail">Operator: {{ $jeep->user->name ?? 'Unknown' }}</p>

                @php
                    $statusMap = [
                        'moving'       => ['bg' => '#43A047', 'color' => '#fff',    'label' => '● LIVE'],
                        'idle'         => ['bg' => '#FBC02D', 'color' => '#4E342E', 'label' => '● IDLE'],
                        'disconnected' => ['bg' => '#E64A19', 'color' => '#fff',    'label' => '◌ NO SIGNAL'],
                    ];
                    $s = $statusMap[$jeep->gps_status] ?? ['bg' => '#9E9E9E', 'color' => '#fff', 'label' => '● UNKNOWN'];
                @endphp

                <div class="statusRow">
                    <div class="statusBadge" style="background-color: {{ $s['bg'] }};">
                        <p class="statusText" style="color: {{ $s['color'] }}; font-size: 11px; font-weight: bold;">
                            {{ $s['label'] }}
                        </p>
                    </div>
                    <div class="statusBadge occupancyBadge" style="background-color: {{ $jeep->is_full ? '#E53935' : '#FFD54F' }};">
                        <p style="color: {{ $jeep->is_full ? '#fff' : '#E65100' }}; font-size: 11px; font-weight: bold;">
                            {{ $jeep->is_full ? 'FULL' : 'SEATS AVAILABLE' }}
                        </p>
                    </div>
                </div>

            </a>
        @endforeach
    </div>

    {{-- Empty state --}}
    <p id="noResultsMsg" style="display:none;font-size:14px;color:#999;margin-top:16px;text-align:center;">
        No active vehicles on this route.
    </p>

    <a href="/" class="backButton">Logout</a>

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
