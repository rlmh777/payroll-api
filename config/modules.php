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

    /*
    |--------------------------------------------------------------------------
    | API resource prefix → application module
    |--------------------------------------------------------------------------
    |
    | First path segment under /api maps to one module code or a list of codes.
    | When a list is given, access is allowed if ANY listed module is enabled.
    | Unlisted prefixes are not gated by module enablement (auth/permissions still apply).
    | The "modules" resource itself is intentionally omitted so admins can re-enable apps.
    |
    */
    'api_resources' => [
        // Payroll
        'accounts' => 'payroll',
        'account-types' => 'payroll',
        'payroll-account-mappings' => 'payroll',
        'allowances' => 'payroll',
        'attendance-settings' => 'payroll',
        'banks' => ['payroll', 'employee'],
        'bank-account-types' => ['payroll', 'employee'],
        'calendar-groups' => 'payroll',
        'clocking-logs' => 'payroll',
        'dashboard' => 'payroll',
        'deduction-types' => 'payroll',
        'historical-employee-allowances' => 'payroll',
        'employee-day-works' => 'payroll',
        'historical-employee-deductions' => 'payroll',
        'journal-entries' => 'payroll',
        'journal-lines' => 'payroll',
        'loans' => 'payroll',
        'loan-types' => 'payroll',
        'payment-methods' => ['payroll', 'employee'],
        'pay-period-groups' => 'payroll',
        'pay-period-schedules' => 'payroll',
        'payrate-frequencies' => ['payroll', 'employee'],
        'payroll-contributions' => 'payroll',
        'payroll-earning-codes' => 'payroll',
        'payroll-earning-lines' => 'payroll',
        'payroll-runs' => 'payroll',
        'payroll-settings' => 'payroll',
        'payrolls' => 'payroll',
        'personal-relief' => 'payroll',
        'report-document-headers' => 'payroll',
        'reports' => 'payroll',
        'schedule-employee-timesheets' => 'payroll',
        'scheduled-work' => 'payroll',
        'scheduler-approvals' => 'payroll',
        'scheduler-daily-metrics' => 'payroll',
        'scheduler-events' => 'payroll',
        'scheduler-metric-definitions' => 'payroll',
        'scheduler-notices' => 'payroll',
        'shift-templates' => 'payroll',
        'social-security' => 'payroll',
        'social-security-contribution-rules' => 'payroll',
        'ss-benefit-types' => 'payroll',
        'tax-calculator-rates' => 'payroll',
        'tax-calculator-accounts' => 'payroll',
        'tax-calculator-runs' => 'payroll',
        'tax-calculator-purchase-ledger' => 'payroll',
        'timesheet-template-departments' => 'payroll',
        'timesheet-templates' => ['payroll', 'employee', 'hr'],
        'timesheets' => 'payroll',
        'pool-distribution-types' => 'payroll',
        'employee-pool-points' => 'payroll',

        // HR
        'vacancies' => 'hr',
        'vacancy-stages' => 'hr',
        'candidate-stages' => 'hr',
        'vacancy-applications' => 'hr',
        'panel' => 'hr',
        'hr-settings' => 'hr',
        'hr-templates' => 'hr',
        'form-access' => 'hr',
        'form-templates' => 'hr',
        'form-assignments' => 'hr',
        'appraisals' => 'hr',
        'pipeline-templates' => ['hr', 'admin'],
        'employee-groups' => 'hr',
        'employee-leaves' => ['hr', 'employee'],
        'employee-leave-balances' => ['hr', 'employee'],
        'leave-statuses' => ['hr', 'employee'],
        'leave-types' => ['hr', 'employee'],
        'employment-leave-entitlements' => 'hr',
        'public-holidays' => ['hr', 'payroll'],
        'departments' => ['hr', 'payroll', 'admin'],
        'department-head-assignments' => ['hr', 'admin'],
        'worksites' => ['hr', 'payroll', 'admin'],
        'job-titles' => ['hr', 'admin'],
        'degrees' => ['hr', 'admin'],
        'institutions' => ['hr', 'admin'],
        'relationships' => ['hr', 'admin'],
        'genders' => ['hr', 'employee', 'admin'],
        'citizenship-statuses' => ['hr', 'employee'],
        'contract-types' => ['hr', 'employee'],
        'document-tags' => ['hr', 'employee'],
        'employee-statuses' => ['hr', 'employee'],
        'employment-statuses' => ['hr', 'employee'],
        'honorifics' => ['hr', 'employee'],
        'contact-type' => ['hr', 'employee'],

        // Employee self-service (+ HR/payroll shared employee APIs)
        'employee-dashboard' => 'employee',
        'my-forms' => 'employee',
        'employee-profile-changes' => ['employee', 'hr'],
        'employees' => ['hr', 'employee', 'payroll'],
        'employee-banks' => ['hr', 'employee', 'payroll'],
        'employee-certifications' => ['hr', 'employee'],
        'employee-compensations' => ['hr', 'payroll'],
        'employee-contacts' => ['hr', 'employee'],
        'employee-default-allowances' => ['hr', 'payroll'],
        'employee-default-deductions' => ['hr', 'payroll'],
        'employee-documents' => ['hr', 'employee'],
        'employee-hours-worked' => ['hr', 'payroll'],
        'employee-incidents' => 'hr',
        'employee-reporting' => 'hr',
        'employee-skills' => ['hr', 'employee'],
        'employee-ss-benefit-status' => ['hr', 'payroll'],
        'employee-work-permits' => 'hr',
        'employment-details' => ['hr', 'employee', 'payroll'],
        'employment-histories' => ['hr', 'employee'],
        'qualifications' => ['hr', 'employee'],

        // Administration
        'application-logs' => 'admin',
        'auth-settings' => 'admin',
        'biometric-devices' => 'admin',
        'company' => 'admin',
        'database-backups' => 'admin',
        'storage-settings' => 'admin',
        'mail-settings' => 'admin',
        'login-page-settings' => 'admin',
        'menus' => 'admin',
        'permissions' => 'admin',
        'roles' => 'admin',
        'users' => 'admin',
        'vendors' => 'admin',
        'countries' => ['admin', 'hr', 'employee'],
        'districts' => ['admin', 'hr', 'employee'],
        'localities' => ['admin', 'hr', 'employee'],
    ],
];
