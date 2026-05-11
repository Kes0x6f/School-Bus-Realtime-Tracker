@extends('layouts.app')

@section('content')
<div id="app" data-page="auth">

<div class="fixed inset-0 w-screen h-screen bg-cover bg-center -z-10"
     style="background-image: url('/images/jeep.png');">
</div>

<div class="fixed top-0 left-0 w-full z-30 shadow-md">
    <div style="background: white; height: 48px;"></div>
    <div style="background: #002D62; height: 48px;"></div>
</div>

<div class="container relative z-20 pt-20">
    <div class="w-full max-w-md px-4 mx-auto flex flex-col items-center">

        <div class="card">
            <p class="logoText">Universidad De Dagupan</p>
            <p class="subLabel">E-Bus Tracker</p>

            <p class="title" style="margin-top: 16px;">Login</p>

            @if ($errors->any())
                <div style="
                    width: 100%;
                    background: #fef2f2;
                    border: 1px solid #fca5a5;
                    border-radius: 10px;
                    padding: 12px 16px;
                    margin-bottom: 12px;
                    color: #dc2626;
                    font-size: 13px;
                    font-weight: 600;
                ">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="/login" style="width: 100%;">
                @csrf

                <input
                    class="input"
                    name="email"
                    type="email"
                    placeholder="Email"
                    value="{{ old('email') }}"
                    required
                    autocomplete="email"
                >
                <input
                    class="input"
                    type="password"
                    name="password"
                    placeholder="Password"
                    required
                    autocomplete="current-password"
                >

                <button type="submit" class="primaryButton">Login</button>
            </form>
        </div>

    </div>
</div>

</div>
@endsection