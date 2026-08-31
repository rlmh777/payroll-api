<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Routes that do not require authentication
    |--------------------------------------------------------------------------
    |
    | Each entry is "METHOD path" (path is relative to /api, without leading slash).
    |
    */
    'public' => [
        'POST login',
        'POST users/reset-password',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authenticated routes that do not require a domain permission
    |--------------------------------------------------------------------------
    */
    'auth_only' => [
        'GET user',
        'GET user/menu',
        'GET user/menus',
        'GET session/navigation',
        'POST logout',
        'GET tokens',
        'POST tokens/create',
        'POST tokens/revoke',
        'POST tokens/revoke-all',
        'DELETE tokens/{tokenId}',
        'GET menus',
    ],

    /*
    |--------------------------------------------------------------------------
    | Resource prefix to permission mapping
    |--------------------------------------------------------------------------
    |
    | GET/HEAD requests require "view", mutating methods require "write".
    | Users receive access through assigned permissions (via roles or direct).
    | There is no hard-coded role bypass; grant permissions in the database.
    |
    */
    'resources' => [
        'accounts' => ['view' => 'view-accounts', 'write' => 'view-accounts'],
        'account-types' => ['view' => 'view-accounts', 'write' => 'view-accounts'],
        'payroll-account-mappings' => ['view' => 'view-account-mappings', 'write' => 'account-mapping-crud'],
        'allowances' => ['view' => 'view-pay-items', 'write' => 'view-pay-items'],
        'attendance-settings' => ['view' => 'view-attendance-settings', 'write' => 'attendance-settings-crud'],
        'bank-account-types' => ['view' => 'view-bank-account-type', 'write' => 'bank-account-type-crud'],
        'banks' => ['view' => 'view-organization', 'write' => 'bank-account-type-crud'],
        'calendar-groups' => ['view' => 'view-calendars', 'write' => 'calendar-crud'],
        'citizenship-statuses' => ['view' => 'view-employees', 'write' => 'employees-crud'],
        'clocking-logs' => ['view' => 'view-clocking-logs', 'write' => 'import-clocking-logs'],
        'company' => ['view' => 'view-organization', 'write' => 'view-organization'],
        'contact-type' => ['view' => 'view-employees', 'write' => 'employees-crud'],
        'contract-types' => ['view' => 'view-employees', 'write' => 'employees-crud'],
        'countries' => ['view' => 'view-country', 'write' => 'country-crud'],
        'database-backups' => ['view' => 'view-database-backup', 'write' => 'database-backup-crud'],
        'dashboard' => ['view' => 'view-dashboard', 'write' => 'view-dashboard'],
        'deduction-types' => ['view' => 'view-pay-items', 'write' => 'view-pay-items'],
        'degrees' => ['view' => 'view-degree', 'write' => 'degree-crud'],
        'department-head-assignments' => ['view' => 'view-department-heads', 'write' => 'department-head-crud'],
        'departments' => ['view' => 'view-department', 'write' => 'department-crud'],
        'districts' => ['view' => 'view-district', 'write' => 'district-crud'],
        'document-tags' => ['view' => 'view-employees', 'write' => 'employees-crud'],
        'employee-banks' => ['view' => 'view-employees', 'write' => 'employees-crud'],
        'employee-certifications' => ['view' => 'view-employees', 'write' => 'employees-crud'],
        'employee-compensations' => ['view' => 'view-employees', 'write' => 'employees-crud'],
        'employee-contacts' => ['view' => 'view-employees', 'write' => 'employees-crud'],
        'employee-default-allowances' => ['view' => 'view-employees', 'write' => 'employees-crud'],
        'employee-default-deductions' => ['view' => 'view-employees', 'write' => 'employees-crud'],
        'employee-documents' => ['view' => 'view-employees', 'write' => 'employees-crud'],
        'employee-groups' => ['view' => 'view-employee-groups', 'write' => 'employee-groups-crud'],
        'employee-hours-worked' => ['view' => 'view-employees', 'write' => 'employees-crud'],
        'employee-incidents' => ['view' => 'view-employees', 'write' => 'employees-crud'],
        'employee-leaves' => ['view' => 'view-leave', 'write' => 'leave-crud'],
        'employee-reporting' => ['view' => 'view-employees', 'write' => 'employees-crud'],
        'employee-skills' => ['view' => 'view-employees', 'write' => 'employees-crud'],
        'employee-ss-benefit-status' => ['view' => 'manager-social-security', 'write' => 'manager-social-security'],
        'employee-statuses' => ['view' => 'view-employees', 'write' => 'employees-crud'],
        'employee-work-permits' => ['view' => 'view-employees', 'write' => 'employees-crud'],
        'employees' => ['view' => 'view-employees', 'write' => 'employees-crud'],
        'employment-details' => ['view' => 'view-employees', 'write' => 'employees-crud'],
        'employment-histories' => ['view' => 'view-employees', 'write' => 'employees-crud'],
        'employment-leave-entitlements' => ['view' => 'view-leave', 'write' => 'leave-crud'],
        'employment-statuses' => ['view' => 'view-employees', 'write' => 'employees-crud'],
        'genders' => ['view' => 'view-employees', 'write' => 'employees-crud'],
        'historical-employee-allowances' => ['view' => 'view-payroll-allowances', 'write' => 'payroll-allowances-crud'],
        'employee-day-works' => ['view' => 'view-employee-day-work', 'write' => 'employee-day-work-crud'],
        'historical-employee-deductions' => ['view' => 'view-payroll', 'write' => 'pay-employees-crud'],
        'honorifics' => ['view' => 'view-employees', 'write' => 'employees-crud'],
        'institutions' => ['view' => 'view-institution', 'write' => 'institution-crud'],
        'job-titles' => ['view' => 'view-job-title', 'write' => 'job-title-crud'],
        'journal-entries' => ['view' => 'view-reports', 'write' => 'view-reports'],
        'journal-lines' => ['view' => 'view-reports', 'write' => 'view-reports'],
        'leave-statuses' => ['view' => 'view-leave', 'write' => 'leave-crud'],
        'leave-types' => ['view' => 'view-leave-types', 'write' => 'leave-crud'],
        'loans' => ['view' => 'view-payroll', 'write' => 'pay-employees-crud'],
        'loan-types' => ['view' => 'view-payroll', 'write' => 'pay-employees-crud'],
        'localities' => ['view' => 'view-locality', 'write' => 'locality-crud'],
        'menus' => ['view' => 'view-menu', 'write' => 'menu-crud'],
        'modules' => ['view' => 'manage-modules', 'write' => 'manage-modules'],
        'notifications' => ['view' => 'view-dashboard', 'write' => 'view-dashboard'],
        'payment-methods' => ['view' => 'view-payroll', 'write' => 'pay-employees-crud'],
        'pay-period-groups' => ['view' => 'view-pay-period-groups', 'write' => 'pay-employees-crud'],
        'pay-period-schedules' => ['view' => 'view-pay-period-groups', 'write' => 'pay-employees-crud'],
        'payrate-frequencies' => ['view' => 'view-payroll', 'write' => 'pay-employees-crud'],
        'payroll-contributions' => ['view' => 'view-payroll', 'write' => 'pay-employees-crud'],
        'payroll-earning-codes' => ['view' => 'view-payroll-earning-codes', 'write' => 'payroll-earning-code-crud'],
        'payroll-earning-lines' => ['view' => 'view-payroll', 'write' => 'pay-employees-crud'],
        'payroll-runs' => ['view' => 'view-payroll', 'write' => 'pay-employees-crud'],
        'payroll-settings' => ['view' => 'manager-tax', 'write' => 'manager-tax'],
        'tax-calculator-rates' => ['view' => 'view-tax-calculator', 'write' => 'tax-calculator-crud'],
        'tax-calculator-accounts' => ['view' => 'view-tax-calculator', 'write' => 'tax-calculator-crud'],
        'tax-calculator-runs' => ['view' => 'view-reports', 'write' => 'tax-calculator-crud'],
        'tax-calculator-purchase-ledger' => ['view' => 'view-reports', 'write' => 'tax-calculator-crud'],
        'payrolls' => ['view' => 'view-payroll', 'write' => 'pay-employees-crud'],
        'permissions' => ['view' => 'view-roles', 'write' => 'permissions-crud'],
        'personal-relief' => ['view' => 'manager-tax', 'write' => 'manager-tax'],
        'pool-distribution-types' => ['view' => 'view-pool-distribution-types', 'write' => 'pool-distribution-type-crud'],
        'employee-pool-points' => ['view' => 'view-employees', 'write' => 'employees-crud'],
        'public-holidays' => ['view' => 'view-holidays', 'write' => 'public-holiday-crud'],
        'qualifications' => ['view' => 'view-employees', 'write' => 'employees-crud'],
        'relationships' => ['view' => 'view-relationship', 'write' => 'relationship-crud'],
        'reports' => ['view' => 'view-reports', 'write' => 'view-reports'],
        'roles' => ['view' => 'view-roles', 'write' => 'roles-crud'],
        'schedule-employee-timesheets' => ['view' => 'view-timesheets', 'write' => 'timesheets-crud'],
        'scheduled-work' => ['view' => 'view-calendars', 'write' => 'calendar-crud'],
        'scheduler-approvals' => ['view' => 'view-calendars', 'write' => 'calendar-crud'],
        'scheduler-events' => ['view' => 'view-calendars', 'write' => 'calendar-crud'],
        'social-security' => ['view' => 'manager-social-security', 'write' => 'manager-social-security'],
        'social-security-contribution-rules' => ['view' => 'manager-social-security', 'write' => 'manager-social-security'],
        'ss-benefit-types' => ['view' => 'manager-social-security', 'write' => 'manager-social-security'],
        'timesheet-template-departments' => ['view' => 'view-timesheet-templates', 'write' => 'view-timesheet-templates'],
        'timesheet-templates' => ['view' => 'view-timesheet-templates', 'write' => 'view-timesheet-templates'],
        'timesheets' => ['view' => 'view-timesheets', 'write' => 'timesheets-crud'],
        'users' => ['view' => 'manager-users', 'write' => 'manager-users'],
        'vendors' => ['view' => 'view-organization', 'write' => 'view-organization'],
        'worksites' => ['view' => 'view-worksite', 'write' => 'worksite-crud'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Explicit route overrides (METHOD path => permission)
    |--------------------------------------------------------------------------
    */
    'overrides' => [
        'POST social-security/calculate-contribution' => 'manager-social-security',
        'GET employees/by-user/{userId}' => 'view-employees',
        'GET employees/{employeeId}/subordinates' => 'view-employees',
        'GET employees/{employee}/ss-contribution-preview' => 'manager-social-security',
        'GET employees/import/template' => 'employees-crud',
        'POST employees/import' => 'employees-crud',
        'GET employees/{employee}/pool-points/current' => 'view-employees',
        'GET employees/{employee}/hours-bank' => 'view-employees',
        'POST employees/{employee}/hours-bank/adjust' => 'employees-crud',
        'POST employees/{employee}/hours-bank/apply-to-leave' => 'leave-crud',
        'POST clocking-logs/import' => 'import-clocking-logs',
        'GET employee-leave-balances' => 'view-leave',
        'GET employee-leave-balances/selectable-employees' => 'view-leave',
        'POST tax-calculator-runs/preview' => 'view-reports',
    ],
];
