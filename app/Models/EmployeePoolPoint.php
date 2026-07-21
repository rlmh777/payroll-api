<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeePoolPoint extends Model
{
    use HasUuids;

    protected $table = 'employee_pool_point';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employee_id',
        'pool_distribution_type_id',
        'points',
        'weight',
        'effective_date',
        'end_date',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'points' => 'decimal:4',
        'weight' => 'decimal:4',
        'effective_date' => 'date',
        'end_date' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function poolDistributionType(): BelongsTo
    {
        return $this->belongsTo(PoolDistributionType::class, 'pool_distribution_type_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function weightedPoints(): float
    {
        return round((float) $this->points * (float) $this->weight, 4);
    }
}
