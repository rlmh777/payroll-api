<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

//Employee Department and Hierarchy - tracked via versioned employment_detail rows
class Department extends Model
{
    protected $table = 'department';
    protected $primaryKey = 'id';

    protected $fillable = [
        'name',
        'parentId',
        'accountId',
        'totalDailyHoursBeforeOvertime',
        'totalWeeklyHoursBeforeOvertime',
        'overtimeThresholdMode',
        'overnightShiftMode',
        'includeLunchHour',
        'lunchHourHours',
    ];

    protected $attributes = [
        'totalDailyHoursBeforeOvertime' => 9,
        'totalWeeklyHoursBeforeOvertime' => 45,
        'overtimeThresholdMode' => 'DAILY_AND_WEEKLY',
        'overnightShiftMode' => 'SPLIT_AT_MIDNIGHT',
        'includeLunchHour' => true,
        'lunchHourHours' => 1,
    ];

    protected $casts = [
        'totalDailyHoursBeforeOvertime' => 'decimal:2',
        'totalWeeklyHoursBeforeOvertime' => 'decimal:2',
        'includeLunchHour' => 'boolean',
        'lunchHourHours' => 'decimal:2',
    ];

    public function parent()
    {
        return $this->belongsTo(Department::class, 'parentId');
    }

    public function children()
    {
        return $this->hasMany(Department::class, 'parentId');
    }

    public function timesheetTemplateAssignments(): HasMany
    {
        return $this->hasMany(TimesheetTemplateDepartment::class, 'department_id');
    }

    public function headAssignments(): HasMany
    {
        return $this->hasMany(DepartmentHeadAssignment::class, 'departmentId');
    }

    public function currentHeadAssignment(): HasOne
    {
        return $this->hasOne(DepartmentHeadAssignment::class, 'departmentId')
            ->whereNull('endDate')
            ->where('isCurrent', true)
            ->latest('startDate');
    }

    public function currentTimesheetTemplateAssignment(): HasOne
    {
        return $this->hasOne(TimesheetTemplateDepartment::class, 'department_id')
            ->orderByDesc('effective_date')
            ->orderByDesc('created_at');
    }

    public function employmentDetails()
    {
        return $this->hasMany(EmploymentDetail::class, 'departmentId');
    }

    public function chartOfAccount()
    {
        return $this->belongsTo(Account::class, 'accountId');
    }
}
