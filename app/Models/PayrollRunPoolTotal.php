<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollRunPoolTotal extends Model
{
    use HasUuids;

    protected $table = 'payroll_run_pool_total';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'payroll_run_id',
        'pool_distribution_type_id',
        'total_amount',
        'notes',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
    ];

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function poolDistributionType(): BelongsTo
    {
        return $this->belongsTo(PoolDistributionType::class, 'pool_distribution_type_id');
    }
}
