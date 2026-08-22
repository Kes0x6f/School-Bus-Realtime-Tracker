<?php

return [
    /*
    |--------------------------------------------------------------------------
    | GPS inactivity policy
    |--------------------------------------------------------------------------
    |
    | These values are the server-owned source of truth for the inactivity
    | command and the client-side stale-status display.
    |
    */

    'gps_stale_seconds' => (int) env('GPS_STALE_SECONDS', 180),
    'auto_end_seconds'  => (int) env('SHIFT_AUTO_END_SECONDS', 1200),
];
