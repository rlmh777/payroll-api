<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkTimesheet extends Model
{
    use HasUuids;

    protected $table = 'work_timesheet';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'start_time',
        'end_time',
        'break_minutes',
        'days',
        'is_active',
    ];

    protected $casts = [
        'days' => 'array',
        'is_active' => 'boolean',
    ];

    public function departments(): HasMany
    {
        return $this->hasMany(WorkTimesheetDepartment::class, 'work_timesheet_id');
    }
}
