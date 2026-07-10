<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepartmentHeadAssignment extends Model
{
    use HasUuids;

    protected $table = 'department_head_assignment';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'departmentId',
        'employeeId',
        'startDate',
        'endDate',
        'isCurrent',
        'appointedById',
        'notes',
    ];

    protected $casts = [
        'startDate' => 'date:Y-m-d',
        'endDate' => 'date:Y-m-d',
        'isCurrent' => 'boolean',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'departmentId');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employeeId');
    }

    public function appointedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'appointedById');
    }
}
