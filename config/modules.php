<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Single-module navigation mode
    |--------------------------------------------------------------------------
    |
    | When true, all menus are shown together without module filtering.
    | When false, the Applications launcher filters menus by the active module.
    |
    */
    'single_module_mode' => env('MODULES_SINGLE_MODE', false),

    'default_module' => env('MODULES_DEFAULT', 'payroll'),
];
