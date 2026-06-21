<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDepartmentHistory extends Model
{
    use HasUuids;

    protected $table = 'employee_department_history';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employeeId',
        'departmentId',
        'previousDepartmentId',
        'startDate',
        'endDate',
        'isCurrent',
        'transferReason',
        'approvalStatus',
        'approvalDate',
        'approverId',
        'notes',
    ];

    protected $casts = [
        'startDate' => 'date:Y-m-d',
        'endDate' => 'date:Y-m-d',
        'approvalDate' => 'date:Y-m-d',
        'isCurrent' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employeeId');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'departmentId');
    }

    public function previousDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'previousDepartmentId');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approverId');
    }
}
