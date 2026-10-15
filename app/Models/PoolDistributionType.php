<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PoolDistributionType extends Model
{
    public const MODE_WEIGHTED_POINTS = 'weighted_points';
    public const MODE_EQUAL_SHARE = 'equal_share';
    public const MODE_DEPARTMENT_EQUAL_SHARE = 'department_equal_share';
    public const MODE_MANUAL = 'manual';
    public const MODE_DISABLED = 'disabled';

    protected $table = 'pool_distribution_type';

    protected $fillable = [
        'code',
        'name',
        'calculation_mode',
        'is_active',
        'requires_hours_eligibility',
        'payroll_earning_code_id',
        'allowance_id',
        'is_taxable',
        'is_ss_subject',
        'sort_order',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'requires_hours_eligibility' => 'boolean',
        'is_taxable' => 'boolean',
        'is_ss_subject' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function payrollEarningCode(): BelongsTo
    {
        return $this->belongsTo(PayrollEarningCode::class, 'payroll_earning_code_id');
    }

    public function allowance(): BelongsTo
    {
        return $this->belongsTo(Allowance::class, 'allowance_id');
    }

    public function employeePoints(): HasMany
    {
        return $this->hasMany(EmployeePoolPoint::class, 'pool_distribution_type_id');
    }

    public function departmentShares(): HasMany
    {
        return $this->hasMany(PoolDistributionTypeDepartmentShare::class, 'pool_distribution_type_id');
    }

    public function isDistributable(): bool
    {
        return $this->is_active
            && in_array($this->calculation_mode, [
                self::MODE_WEIGHTED_POINTS,
                self::MODE_EQUAL_SHARE,
                self::MODE_DEPARTMENT_EQUAL_SHARE,
            ], true);
    }
}
