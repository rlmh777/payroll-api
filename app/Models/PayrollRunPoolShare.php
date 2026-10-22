<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollRunPoolShare extends Model
{
    use HasUuids;

    protected $table = 'payroll_run_pool_share';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'payroll_run_id',
        'pool_distribution_type_id',
        'employee_id',
        'batch_id',
        'shares',
        'payout_amount',
    ];

    protected $casts = [
        'shares' => 'decimal:4',
        'payout_amount' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function poolDistributionType(): BelongsTo
    {
        return $this->belongsTo(PoolDistributionType::class, 'pool_distribution_type_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PayrollRunPoolShareBatch::class, 'batch_id');
    }
}
