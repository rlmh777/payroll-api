<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkTimesheetDepartment extends Model
{
    use HasUuids;

    protected $table = 'work_timesheet_department';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'work_timesheet_id',
        'department_id',
        'effective_date',
        'notes',
    ];

    protected $casts = [
        'effective_date' => 'date:Y-m-d',
    ];

    public function workTimesheet(): BelongsTo
    {
        return $this->belongsTo(WorkTimesheet::class, 'work_timesheet_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }
}
