<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimesheetTemplateDepartment extends Model
{
    use HasUuids;

    protected $table = 'timesheet_template_department';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'timesheet_template_id',
        'department_id',
        'effective_date',
        'notes',
    ];

    protected $casts = [
        'effective_date' => 'date:Y-m-d',
    ];

    public function timesheetTemplate(): BelongsTo
    {
        return $this->belongsTo(TimesheetTemplate::class, 'timesheet_template_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }
}
