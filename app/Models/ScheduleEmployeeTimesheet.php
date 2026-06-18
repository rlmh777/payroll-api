<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleEmployeeTimesheet extends Model
{
    use HasUuids;

    protected $table = 'schedule_employee_timesheet';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employeeId',
        'calendar_group_id',
        'date',
        'startTime',
        'endTime',
        'approvalStatus',
        'approvalDate',
        'approverId',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employeeId');
    }
}
