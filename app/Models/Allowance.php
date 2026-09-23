<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Allowance extends Model
{
    use HasUuids;

    protected $table = 'allowance';
    protected $primarykey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'isTaxable',
        'isSocialSecurityDeductable',
        'note',
        'defaultAmount',
        'payroll_earning_code_id',
        'accountId',
    ];

    public function payrollEarningCode(): BelongsTo
    {
        return $this->belongsTo(PayrollEarningCode::class, 'payroll_earning_code_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accountId');
    }

    public function employeeDefaultAllowance(): HasMany
    {
        return $this->hasMany(EmployeeDefaultAllowance::class);
    }

    public function employeeHistoricalAllowance(): HasMany
    {
        return $this->hasMany(HistoricalEmployeeAllowance::class);
    }

}
