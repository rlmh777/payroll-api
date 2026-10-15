<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchedulerMetricDefinition extends Model
{
    public const VALUE_TYPE_INTEGER = 'integer';

    public const VALUE_TYPE_DECIMAL = 'decimal';

    protected $table = 'scheduler_metric_definition';

    protected $fillable = [
        'code',
        'name',
        'short_label',
        'value_type',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function dailyMetrics(): HasMany
    {
        return $this->hasMany(SchedulerDailyMetric::class, 'scheduler_metric_definition_id');
    }
}
