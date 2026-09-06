<?php

return [

    /*
    |--------------------------------------------------------------------------
    | View Storage Paths
    |--------------------------------------------------------------------------
    |
    | Blade templates are loaded from the application's resources directory.
    |
    */

    'paths' => [
        resource_path('views'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Compiled View Path
    |--------------------------------------------------------------------------
    |
    | Keep generated PHP outside the web root. This deliberately does not use
    | an environment override so a malformed host path cannot become a public
    | directory name when configuration is shared across environments.
    |
    */

    'compiled' => storage_path('framework/views'),

];
