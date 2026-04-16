@extends('layouts.app')

@section('content')

<div class="fullScreen slideIn"
id="app" 
data-page="active-jeeps">

    <p class="pageLabelDark">UDD CAMPUS SHUTTLE SERVICE</p>
    <p class="headerTitle">Available E-Busses</p>

    <div
        id="vehicleList"
        data-vehicle-ids='@json($jeeps->pluck("id"))'
        style="width: 100%; display: flex; flex-direction: column; align-items: center;"
    >

        @foreach($jeeps as $jeep)
            <a href="/student/track/{{ $jeep->id }}" 
                class="jeepCard"
                data-vehicle-id="{{ $jeep->id }}">

                <p class="jeepRoute">
                    Route: {{ $jeep->route_name }}
                </p>

                <p class="jeepDetail">
                    Operator: {{ $jeep->user->name ?? 'Unknown' }}
                </p>

                <div class="statusRow">

                    <!-- LIVE STATUS -->
                    <div class="statusBadge">
                        <p class="statusText">● LIVE</p>
                    </div>

                    <!-- CAPACITY -->
                    <div class="statusBadge occupancyBadge" style="background-color: {{ $jeep->is_full ? '#E53935' : '#FFD54F' }};">
                        <p style="color: {{ $jeep->is_full ? '#fff' : '#E65100' }}; font-size: 11px; font-weight: bold;">
                        {{ $jeep->is_full ? 'FULL' : 'SEATS AVAILABLE' }}
                        </p>
                    </div>

                </div>

            </a>
        @endforeach

    </div>

    <a href="/" class="backButton">
        Logout
    </a>

</div>
@endsection