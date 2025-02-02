<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relation\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Payroll extends Model
{
    use HasUuids;

    protected $table = 'payroll';
    protected $primarykey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employeeId',
        'date',
        'totalRegularHours',
        'totalOvertimeHours',
        'employeeSocialSecurityAmount',
        'employerSocialSecurityAmount',
        'incomeTaxAmount',
        'grossSalary',
        'netSalary',
        'totalDeductions',
        'totalAllowances',
        'taxCalculationModeId',
        'socialSecurityCalculationModeId',
        'paymentMethodId'
        'note'
    ];

    public function employee(): BelongsTo {
        return $this->belongsTo(Employee::class);
    }

    public function taxCalculationMode(): BelongsTo {
        return $this->belongsTo(CalculationMode::class);
    }

    public function socialSecurityCalculationMode(): BelongsTo {
        return $this->belongsTo(CalculationMode::class);
    }

    public function historicalDeductions(): HasMany {
        return $this->hasMany(HistoricalEmployeeDeduction::class);
    }

    public function paymentMethods(): BelongsTo {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function historicalAllowances(): HasMany {
        return $this->hasMany(HistoricalEmployeeAllowance::class);
    }


}
