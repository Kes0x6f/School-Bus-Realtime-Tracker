<?php

it('registers one protected broadcast authentication route', function () {
    $broadcastRoutes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => $route->uri() === 'broadcasting/auth')
        ->values();

    expect($broadcastRoutes)->toHaveCount(1);

    $middleware = $broadcastRoutes->first()->middleware();

    expect($middleware)
        ->toContain('web')
        ->toContain('auth')
        ->toContain('active');
});
