<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeLeave extends Model
{
    use HasUuids;

    protected $table = 'employee_leave';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected static function booted(): void
    {
        static::created(function (EmployeeLeave $leave) {
            try {
                app(\App\Modules\Hr\Services\Leave\LeaveWorkflowService::class)
                    ->ensurePipelineStarted($leave);
            } catch (\Throwable) {
                // Pipeline start is best-effort during create.
            }
        });
    }

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
        'paymentTreatment',
        'paymentConfirmedAt',
        'paymentConfirmedByUserId',
        'paidInPayrollRunId',
        'statusNote',
        'approvalDate',
        'approverId',
    ];

    protected $casts = [
        'startDate' => 'date:Y-m-d',
        'endDate' => 'date:Y-m-d',
        'approvalDate' => 'date:Y-m-d',
        'paymentConfirmedAt' => 'datetime',
        'multiplier' => 'float',
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

    public function paymentConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paymentConfirmedByUserId');
    }

    public function paidInPayrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'paidInPayrollRunId');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EmployeeLeaveAttachment::class, 'employeeLeaveId');
    }

    public function getStatusCodeAttribute(): ?string
    {
        return $this->leaveStatus?->code;
    }
}
