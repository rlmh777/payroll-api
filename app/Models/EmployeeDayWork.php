<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDayWork extends Model
{
    use HasUuids;

    protected $table = 'employee_day_work';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'employeeId',
        'employmentDetailId',
        'employeeCompensationId',
        'departmentId',
        'date',
        'units',
        'dailyRate',
        'amount',
        'note',
        'approvalStatus',
        'approvalDate',
        'approverId',
        'createdById',
        'updatedById',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'approvalDate' => 'date:Y-m-d',
        'units' => 'decimal:2',
        'dailyRate' => 'decimal:2',
        'amount' => 'decimal:2',
        'departmentId' => 'integer',
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

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'departmentId');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approverId');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'createdById');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'updatedById');
    }

    public function isApproved(): bool
    {
        return strtoupper((string) $this->approvalStatus) === 'APPROVED';
    }
}
