<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollRunPoolDistribution extends Model
{
    use HasUuids;

    protected $table = 'payroll_run_pool_distribution';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'payroll_run_id',
        'pool_distribution_type_id',
        'employee_id',
        'department_id',
        'department_percent',
        'department_amount',
        'worked_this_period',
        'points',
        'weight',
        'weighted_points',
        'share_ratio',
        'amount',
        'is_eligible',
        'eligibility_reason',
        'worked_hours',
        'expected_hours',
        'bank_hours_applied',
    ];

    protected $casts = [
        'points' => 'decimal:4',
        'weight' => 'decimal:4',
        'weighted_points' => 'decimal:4',
        'share_ratio' => 'decimal:8',
        'amount' => 'decimal:2',
        'is_eligible' => 'boolean',
        'worked_hours' => 'decimal:4',
        'expected_hours' => 'decimal:4',
        'bank_hours_applied' => 'decimal:4',
        'department_percent' => 'decimal:2',
        'department_amount' => 'decimal:2',
        'worked_this_period' => 'boolean',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function poolDistributionType(): BelongsTo
    {
        return $this->belongsTo(PoolDistributionType::class, 'pool_distribution_type_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
