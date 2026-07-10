<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledWork extends Model
{
    use HasUuids;

    protected $table = 'scheduled_work';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'startDate',
        'endDate',
        'startTime',
        'endTime',
        'employeeId',
        'employmentDetailId',
        'departmentId',
        'worksiteId',
        'description',
        'rate',
        'includeLunchHour',
        'lunchHourHours',
    ];

    protected $attributes = [
        'lunchHourHours' => 1,
    ];

    protected $casts = [
        'startDate' => 'date:Y-m-d',
        'endDate' => 'date:Y-m-d',
        'rate' => 'decimal:2',
        'includeLunchHour' => 'boolean',
        'lunchHourHours' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employeeId');
    }

    public function employmentDetail(): BelongsTo
    {
        return $this->belongsTo(EmploymentDetail::class, 'employmentDetailId');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'departmentId');
    }

    public function worksite(): BelongsTo
    {
        return $this->belongsTo(Worksite::class, 'worksiteId');
    }
}
