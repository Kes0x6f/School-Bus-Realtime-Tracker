@extends('layouts.app')

@section('content')
<div class="flex items-center justify-center min-h-screen p-4">

    <div class="w-full max-w-sm bg-white rounded-2xl shadow-lg p-6 space-y-4">

        <h1 class="text-xl font-bold text-center">Jeep Tracker</h1>

        <a href="/driver"
           class="block text-center bg-blue-600 text-white p-3 rounded-xl">
            Login as Driver
        </a>

        <a href="/student"
           class="block text-center bg-green-600 text-white p-3 rounded-xl">
            Login as Student
        </a>

    </div>

</div>
@endsection