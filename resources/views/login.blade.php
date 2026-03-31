@extends('layouts.app')

@section('content')

<!-- REMOVE flex wrapper -->
<!-- REMOVE inner container -->

<div class="container" style="background-image: url('{{ asset('images/bgg.png') }}');">

    <!-- LANDING -->
    <div id="landing">
        <div class="card">
            <p class="logoText">Universidad De Dagupan</p>
            <p class="subLabel">E-Bus Tracker</p>

            <button onclick="show('studentLogin')" class="primaryButton">
                Log in as Student
            </button>

            <button onclick="show('driverLogin')" class="secondaryButton">
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

        <button onclick="show('landing')" class="backLink">
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
        <button onclick="show('landing')" class="backLink">
            ← Return Home
        </button>
    </div>
</div>

</div>


<script>
function show(id) {
    document.getElementById('landing').style.display = 'none';
    document.getElementById('studentLogin').style.display = 'none';
    document.getElementById('driverLogin').style.display = 'none';

    document.getElementById(id).style.display = 'block';
}
</script>

@endsection