<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeLeave extends Model
{
    use HasUuids;

    protected $table = 'employee_leave';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employeeId',
        'departmentId',
        'leaveTypeId',
        'leaveStatusId',
        'startDate',
        'endDate',
        'fromTime',
        'toTime',
        'duration',
        'totalDays',
        'notes',
        'multiplier',
        'statusNote',
        'approvalDate',
        'approverId',
    ];

    protected $casts = [
        'startDate' => 'date:Y-m-d',
        'endDate' => 'date:Y-m-d',
        'approvalDate' => 'date:Y-m-d',
    ];

    protected $appends = [
        'statusCode',
    ];

    public function employee(): BelongsTo {
        return $this->belongsTo(Employee::class, 'employeeId');
    }

    public function department(): BelongsTo {
        return $this->belongsTo(Department::class, 'departmentId');
    }

    public function leaveType(): BelongsTo {
        return $this->belongsTo(LeaveType::class, 'leaveTypeId');
    }

    public function leaveStatus(): BelongsTo {
        return $this->belongsTo(LeaveStatus::class, 'leaveStatusId');
    }

    public function approver(): BelongsTo {
        return $this->belongsTo(Employee::class, 'approverId');
    }

    public function getStatusCodeAttribute(): ?string
    {
        return $this->leaveStatus?->code;
    }
}
