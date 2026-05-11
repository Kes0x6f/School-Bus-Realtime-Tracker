@extends('layouts.app')

@section('content')

<div class="fullScreen slideIn"
id="app"
data-page="active-jeeps">

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
            @php
                $statusMap = [
                    'moving'       => ['bg' => '#43A047', 'color' => '#fff',    'label' => '● LIVE'],
                    'idle'         => ['bg' => '#FBC02D', 'color' => '#4E342E', 'label' => '● IDLE'],
                    'disconnected' => ['bg' => '#E64A19', 'color' => '#fff',    'label' => '◌ NO SIGNAL'],
                ];
                $s = $statusMap[$jeep->gps_status] ?? ['bg' => '#9E9E9E', 'color' => '#fff', 'label' => '● UNKNOWN'];
            @endphp

            <a href="/student/track/{{ $jeep->id }}"
                class="jeepCard"
                data-vehicle-id="{{ $jeep->id }}"
                data-route="{{ $jeep->route_name }}">

                <p class="jeepRoute">
                    Route: {{ $jeep->route_name }}
                </p>

                <p class="jeepDetail">
                    Operator: {{ $jeep->user->name ?? 'Unknown' }}
                </p>

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

    {{-- Empty state — shown by JS when filter has no results --}}
    <p id="noResultsMsg" style="
        display: none;
        font-size: 14px;
        color: #999;
        margin-top: 16px;
        text-align: center;
    ">No active vehicles on this route.</p>

    <a href="/" class="backButton">
        Logout
    </a>

</div>
@endsection