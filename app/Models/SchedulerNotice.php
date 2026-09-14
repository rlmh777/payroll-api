<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SchedulerNotice extends Model
{
    use HasUuids;

    public const AUDIENCE_COMPANY = 'company';

    public const AUDIENCE_DEPARTMENTS = 'departments';

    public const AUDIENCE_EMPLOYEES = 'employees';

    protected $table = 'scheduler_notice';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'title',
        'description',
        'start_date',
        'end_date',
        'audience_type',
        'color',
        'is_active',
        'created_by_id',
        'updated_by_id',
    ];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'is_active' => 'boolean',
    ];

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(
            Department::class,
            'scheduler_notice_department',
            'scheduler_notice_id',
            'department_id',
        )->withTimestamps();
    }

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(
            Employee::class,
            'scheduler_notice_employee',
            'scheduler_notice_id',
            'employee_id',
        )->withTimestamps();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }
}
