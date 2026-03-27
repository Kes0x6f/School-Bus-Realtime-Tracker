@extends('layouts.app')

@section('content')
<div class="p-4 max-w-md mx-auto">

    <div class="flex justify-end mb-4">
        <a href="/"
        class="text-sm text-red-500 font-medium">
            Logout
        </a>
    </div>

    <h2 class="text-xl font-bold mb-4">Active Jeeps</h2>

    @foreach($jeeps as $jeep)
        <a href="/student/track/{{ $jeep->id }}"
           class="block bg-white shadow rounded-2xl p-4 mb-3">

            <div class="font-semibold">
                {{ $jeep->route_name }}
            </div>

            <div class="text-sm">
                Status:
                @if($jeep->is_full)
                    <span class="text-red-500">Full</span>
                @else
                    <span class="text-green-600">Available</span>
                @endif
            </div>

        </a>
    @endforeach

</div>
@endsection 