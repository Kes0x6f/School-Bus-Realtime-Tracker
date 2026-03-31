@extends('layouts.app')

@section('content')

<div class="fullScreen slideIn">

    <p class="pageLabelDark">UDD CAMPUS SHUTTLE SERVICE</p>
    <p class="headerTitle">Available E-Busses</p>

    <div style="width: 100%; display: flex; flex-direction: column; align-items: center;">

        @foreach($jeeps as $jeep)
            <a href="/student/track/{{ $jeep->id }}" class="jeepCard">

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
                    <div class="statusBadge" style="background-color: #FFD54F;">
                        <p style="color: #E65100; font-size: 11px; font-weight: bold;">
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

<script>
window.addEventListener("load", () => {

    if (!window.Echo) {
        console.error("Echo not loaded");
        return;
    }
    
    let vehicleIds = @json($jeeps->pluck('id'));
    let reloading = false;
    let polling = false;
    
    vehicleIds.forEach(id => {

        Echo.private(`vehicle.${id}`)
            .listen('.location.updated', () => {
                if (reloading) return;
                reloading = true;

                console.log("Vehicle updated:", id);

                location.reload();

            });

    });

    async function checkActiveVehicles() {
        if (polling) return;
        polling = true;

        try {
            const res = await fetch('/api/vehicles/active');
            const data = await res.json();

            const newIds = data.map(v => v.id);

            const changed =
                newIds.length !== vehicleIds.length ||
                newIds.some(id => !vehicleIds.includes(id));

            if (changed) {
                console.log("Active vehicles changed");
                location.reload();
                return;
            }

        } catch (e) {
            console.error("Polling error", e);
        } finally {
            polling = false;
        }
    }

    setInterval(checkActiveVehicles, 5000); 

});
</script>

@endsection