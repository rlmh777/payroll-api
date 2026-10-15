<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PoolDistributionTypeDepartmentShare extends Model
{
    protected $table = 'pool_distribution_type_department_share';

    protected $fillable = [
        'pool_distribution_type_id',
        'department_id',
        'percent',
    ];

    protected $casts = [
        'percent' => 'decimal:2',
    ];

    public function poolDistributionType(): BelongsTo
    {
        return $this->belongsTo(PoolDistributionType::class, 'pool_distribution_type_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }
};
