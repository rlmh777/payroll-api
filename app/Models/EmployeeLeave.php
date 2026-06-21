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
        'leaveTypeId',
        'startDate',
        'endDate',
        'fromTime',
        'toTime',
        'duration',
        'totalDays',
        'notes',
        'multiplier',
        'approvalStatus',
        'approvalDate',
        'approverId'
    ];

    public function employee(): BelongsTo {
        return $this->belongsTo(Employee::class, 'employeeId');
    }

    public function leaveType(): BelongsTo {
        return $this->belongsTo(LeaveType::class, 'leaveTypeId');
    }
}
