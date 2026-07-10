<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payroll extends Model
{
    use HasUuids;

    protected $table = 'payroll';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employeeId',
        'employmentDetailId',
        'employeeCompensationId',
        'payroll_run_id',
        'departmentId',
        'date',
        'totalRegularHours',
        'totalOvertimeHours',
        'holidayHours',
        'tipsAmount',
        'bonusAmount',
        'employeeSocialSecurityAmount',
        'employerSocialSecurityAmount',
        'incomeTaxAmount',
        'grossSalary',
        'taxableGross',
        'ssWages',
        'netSalary',
        'employerCostTotal',
        'totalDeductions',
        'totalAllowances',
        'applied_ss_rule_id',
        'applied_ss_tier_id',
        'ss_calculation_detail',
        'applied_personal_relief_id',
        'tax_calculation_detail',
        'paymentMethodId',
        'employeeBankId',
        'bankId',
        'accountNumber',
        'note',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'totalRegularHours' => 'decimal:2',
        'totalOvertimeHours' => 'decimal:2',
        'holidayHours' => 'decimal:2',
        'tipsAmount' => 'decimal:2',
        'bonusAmount' => 'decimal:2',
        'employeeSocialSecurityAmount' => 'decimal:2',
        'employerSocialSecurityAmount' => 'decimal:2',
        'incomeTaxAmount' => 'decimal:2',
        'grossSalary' => 'decimal:2',
        'taxableGross' => 'decimal:2',
        'ssWages' => 'decimal:2',
        'netSalary' => 'decimal:2',
        'employerCostTotal' => 'decimal:2',
        'totalDeductions' => 'decimal:2',
        'totalAllowances' => 'decimal:2',
        'ss_calculation_detail' => 'array',
        'tax_calculation_detail' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employeeId');
    }

    public function employmentDetail(): BelongsTo
    {
        return $this->belongsTo(EmploymentDetail::class, 'employmentDetailId');
    }

    public function employeeCompensation(): BelongsTo
    {
        return $this->belongsTo(EmployeeCompensation::class, 'employeeCompensationId');
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'departmentId');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'paymentMethodId');
    }

    public function employeeBank(): BelongsTo
    {
        return $this->belongsTo(EmployeeBank::class, 'employeeBankId');
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bankId');
    }

    public function earningLines(): HasMany
    {
        return $this->hasMany(PayrollEarningLine::class, 'payroll_id');
    }

    public function appliedSsRule(): BelongsTo
    {
        return $this->belongsTo(SocialSecurityContributionRule::class, 'applied_ss_rule_id');
    }

    public function appliedSsTier(): BelongsTo
    {
        return $this->belongsTo(SocialSecurity::class, 'applied_ss_tier_id');
    }

    public function appliedPersonalRelief(): BelongsTo
    {
        return $this->belongsTo(PersonalRelief::class, 'applied_personal_relief_id');
    }
}
