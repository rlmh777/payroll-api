<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchedulerDailyMetric extends Model
{
    protected $table = 'scheduler_daily_metric';

    protected $fillable = [
        'scheduler_metric_definition_id',
        'date',
        'value',
        'updated_by_id',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'value' => 'decimal:2',
        'scheduler_metric_definition_id' => 'integer',
    ];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(SchedulerMetricDefinition::class, 'scheduler_metric_definition_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }
}
