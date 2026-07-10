<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleEmployeeTimesheet extends Model
{
    use HasUuids;

    protected $table = 'schedule_employee_timesheet';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employeeId',
        'employmentDetailId',
        'departmentId',
        'calendar_group_id',
        'series_id',
        'date',
        'startTime',
        'endTime',
        'include_lunch_hour',
        'lunch_hour_hours',
        'approvalStatus',
        'approvalDate',
        'approverId',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'include_lunch_hour' => 'boolean',
        'lunch_hour_hours' => 'decimal:2',
    ];

    protected $attributes = [
        'lunch_hour_hours' => 1,
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employeeId');
    }

    public function employmentDetail(): BelongsTo
    {
        return $this->belongsTo(EmploymentDetail::class, 'employmentDetailId');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'departmentId');
    }
}
