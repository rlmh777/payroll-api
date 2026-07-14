<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Income tax
    |--------------------------------------------------------------------------
    | Stored in payroll_setting.incomeTaxRate. This value is only used when
    | seeding the initial payroll_setting row.
    */
    'income_tax_rate' => 0.25,
    'second_relief_amount' => 100.00,

    /*
    |--------------------------------------------------------------------------
    | Overtime pay multiplier applied to hourly rate for overtime hours.
    |--------------------------------------------------------------------------
    */
    'overtime_multiplier' => (float) env('PAYROLL_OVERTIME_MULTIPLIER', 1.5),

    /*
    |--------------------------------------------------------------------------
    | Pay periods per calendar year by payrate frequency name (case-insensitive).
    |--------------------------------------------------------------------------
    */
    'periods_per_year' => [
        'monthly' => 12,
        'biweekly' => 26,
        'weekly' => 52,
    ],

    'default_periods_per_year' => 26,

    /*
    |--------------------------------------------------------------------------
    | Employee user accounts
    |--------------------------------------------------------------------------
    | Login email is generated as {lastname}.{firstname}@{employee_login_domain}.
    | Users can sign in with that username or the full email address.
    */
    'employee_default_password' => env('EMPLOYEE_DEFAULT_PASSWORD', 'ChangeMe123!'),
    'employee_login_domain' => env('EMPLOYEE_LOGIN_DOMAIN', ''),
];
