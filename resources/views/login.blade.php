@extends('layouts.app')

@section('content')
<div id="app" data-page="auth">
<div class="fixed inset-0 w-screen h-screen bg-cover bg-center -z-10"
        style="background-image: url('/images/jeep.png');">
</div>

<div class="fixed top-0 left-0 w-full z-30 shadow-md">

    <!-- WHITE TOP STRIP -->
    <div style="background: white; height: 48px;"></div>

    <!-- BLUE BAR -->
    <div style="background: #002D62; height: 48px;"></div>

</div>


<div class="container relative z-20 pt-20">
    
    <!-- Content -->
    <div class="w-full max-w-md px-4 mx-auto flex flex-col items-center">

    <!-- LANDING -->
    <div id="landing" class="w-full">
        <div class="card">
            <p class="logoText">Universidad De Dagupan</p>
            <p class="subLabel">E-Bus Tracker</p>

            <button id="toStudent" class="primaryButton">
                Log in as Student
            </button>

            <button id="toDriver" class="secondaryButton">
                Log in as Driver
            </button>
        </div>
    </div>

<!-- STUDENT LOGIN -->
<div id="studentLogin" style="display:none;">
    <div class="card">
        <p class="title">Student Login</p>

        <form method="POST" action="/login">
            @csrf

            <input class="input" name="email" placeholder="Email" required>
            <input class="input" type="password" name="password" placeholder="Password" required>

            <button type="submit" class="secondaryButton">Login</button>
        </form>   

        <button class="backBtn" data-target="landing">
            ← Return Home
        </button>
    </div>
</div>

<!-- DRIVER LOGIN -->
<div id="driverLogin" style="display:none;">
    <div class="card">
        <p class="title">Driver Login</p>
            <form method="POST" action="/login">
                @csrf

                <input class="input" name="email" placeholder="Email" required>
                <input class="input" type="password" name="password" placeholder="Password" required>

                <button type="submit" class="secondaryButton">Login</button>
            </form>
        <button class="backBtn" data-target="landing">
            ← Return Home
        </button>
    </div>
</div>

</div>
</div>
</div>

@endsection