<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Timesheet extends Model
{
    use HasUuids;

    protected $table = 'timesheet';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employeeId',
        'employmentDetailId',
        'employeeCompensationId',
        'date',
        'slotIndex',
        'clockInTime',
        'clockInDeviceId',
        'clockOutTime',
        'clockOutDeviceId',
        'roundOffClockInTime',
        'roundOffClockOutTime',
        'clockedHoursWorked',
        'includeLunchHour',
        'lunchHourHours',
        'hoursWorked',
        'regularHours',
        'overtimeHours',
        'holidayHours',
        'unpaidHours',
        'isPaid',
        'paidHours',
        'workingStatus',
        'departmentId',
        'worksiteId',
        'payType',
        'hourlyRate',
        'weeklySalary',
        'baseSalary',
        'approvalStatus',
        'approvedBy',
        'approvedAt',
        'remarks',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'slotIndex' => 'integer',
        'clockInTime' => 'datetime:Y-m-d H:i:s',
        'clockOutTime' => 'datetime:Y-m-d H:i:s',
        'roundOffClockInTime' => 'datetime:Y-m-d H:i:s',
        'roundOffClockOutTime' => 'datetime:Y-m-d H:i:s',
        'clockedHoursWorked' => 'decimal:2',
        'includeLunchHour' => 'boolean',
        'lunchHourHours' => 'decimal:2',
        'hoursWorked' => 'decimal:2',
        'regularHours' => 'decimal:2',
        'overtimeHours' => 'decimal:2',
        'holidayHours' => 'decimal:2',
        'unpaidHours' => 'decimal:2',
        'isPaid' => 'boolean',
        'paidHours' => 'decimal:2',
        'hourlyRate' => 'decimal:2',
        'weeklySalary' => 'decimal:2',
        'baseSalary' => 'decimal:2',
        'approvedAt' => 'datetime:Y-m-d H:i:s',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employeeId');
    }

    public function employmentDetail(): BelongsTo
    {
        return $this->belongsTo(EmploymentDetail::class, 'employmentDetailId');
    }

    public function employeeCompensation(): BelongsTo
    {
        return $this->belongsTo(EmployeeCompensation::class, 'employeeCompensationId');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approvedBy');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'departmentId');
    }

    public function worksite(): BelongsTo
    {
        return $this->belongsTo(Worksite::class, 'worksiteId');
    }
}
