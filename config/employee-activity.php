<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Employee Time Travel activity logging
    |--------------------------------------------------------------------------
    |
    | Models listed here are observed for created/updated/deleted events and
    | written to Spatie activity_log with properties.employee_id for timeline
    | queries.
    |
    */
    'enabled' => (bool) env('EMPLOYEE_ACTIVITY_LOG_ENABLED', true),

    'log_name' => 'employee',

    /** @var list<class-string<\Illuminate\Database\Eloquent\Model>> */
    'models' => [
        \App\Models\Employee::class,
        \App\Modules\Hr\Models\Employee::class,
        \App\Modules\Core\Models\Person::class,
        \App\Models\EmploymentDetail::class,
        \App\Models\EmploymentLeaveEntitlement::class,
        \App\Models\EmployeeCompensation::class,
        \App\Models\EmployeeDefaultAllowance::class,
        \App\Models\EmployeeDefaultDeduction::class,
        \App\Models\HistoricalEmployeeAllowance::class,
        \App\Models\HistoricalEmployeeDeduction::class,
        \App\Models\EmployeeBank::class,
        \App\Models\EmployeeContact::class,
        \App\Models\EmployeeDocument::class,
        \App\Models\EmployeeIncident::class,
        \App\Models\EmployeeIncidentAttachment::class,
        \App\Models\Qualification::class,
        \App\Models\EmployeeCertification::class,
        \App\Models\EmployeeSkill::class,
        \App\Models\EmployeeLeave::class,
        \App\Models\EmployeeLeaveAttachment::class,
        \App\Models\EmployeeSsBenefitStatus::class,
        \App\Models\EmployeeWorkPermit::class,
        \App\Models\EmploymentHistory::class,
        \App\Models\Timesheet::class,
        \App\Models\EmployeeDayWork::class,
        \App\Models\EmployeePoolPoint::class,
        \App\Models\EmployeeHoursBank::class,
        \App\Models\EmployeeHoursBankLedger::class,
        \App\Models\Loan::class,
        \App\Models\EmployeeReporting::class,
        \App\Models\PayrollEarningLine::class,
        \App\Models\PayrollContribution::class,
    ],

    /** Attribute keys never stored in activity payloads. */
    'redact_attributes' => [
        'password',
        'remember_token',
        'socialSecurityNumber',
        'taxIdentificationNumber',
        'passportNumber',
        'votersId',
    ],
];
