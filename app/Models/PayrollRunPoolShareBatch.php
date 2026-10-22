<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRunPoolShareBatch extends Model
{
    use HasUuids;

    protected $table = 'payroll_run_pool_share_batch';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'payroll_run_id',
        'pool_distribution_type_id',
        'total_amount',
        'source_name',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
    ];

    public function shares(): HasMany
    {
        return $this->hasMany(PayrollRunPoolShare::class, 'batch_id');
    }

    public function poolDistributionType(): BelongsTo
    {
        return $this->belongsTo(PoolDistributionType::class, 'pool_distribution_type_id');
    }
}
