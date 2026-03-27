<?php

use App\Events\JeepMoved;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\GpsController;

Route::get('/', function () {
    return view('login');
});

Route::get('/map', function () {
    return view(view: 'map');
});

Route::get('/move', function () {
    event(new JeepMoved(16.351212533761666, 120.3407345106514));
});

Route::get('/debug-broadcast', function () {
    event(new App\Events\JeepMoved(16.052, 120.341));
    return 'event dispatched';
});



/*
|--------------------------------------------------------------------------
| MOCK DRIVER
|--------------------------------------------------------------------------
*/

Route::get('/driver', function () {
    return view('driver.dashboard');
});

/*
|--------------------------------------------------------------------------
| MOCK STUDENT
|--------------------------------------------------------------------------
*/

Route::get('/student', function () {

    // Fake active jeeps
    $jeeps = [
        (object)[
            'id' => 1,
            'route_name' => 'Route A - Mangaldan',
            'is_full' => false
        ],
        (object)[
            'id' => 2,
            'route_name' => 'Route B - Calasiao',
            'is_full' => true
        ],
    ];

    return view('student.active-jeeps', compact('jeeps'));
});

Route::get('/student/track/{id}', function ($id) {

    return view('student.track', [
        'jeepId' => $id
    ]);});