<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Single-module navigation mode
    |--------------------------------------------------------------------------
    |
    | When true, all menus are shown together without module filtering.
    | Menus are tagged as payroll in the database until multi-module split.
    |
    */
    'single_module_mode' => env('MODULES_SINGLE_MODE', true),

    'default_module' => env('MODULES_DEFAULT', 'payroll'),
];
