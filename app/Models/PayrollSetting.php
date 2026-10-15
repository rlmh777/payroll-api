<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollSetting extends Model
{
    protected $table = 'payroll_setting';

    protected $fillable = [
        'incomeTaxRate',
        'secondReliefAmount',
        'postVacationPayToVacationAccount',
        'timesheetLockBeforeDate',
        'timesheetAutoLockEnabled',
        'timesheetAutoLockTime',
        'timesheetAutoLockDaysAfterPayDate',
        'timesheetUnlockStartDate',
        'timesheetUnlockEndDate',
    ];

    protected $casts = [
        'incomeTaxRate' => 'decimal:4',
        'secondReliefAmount' => 'decimal:2',
        'postVacationPayToVacationAccount' => 'boolean',
        'timesheetLockBeforeDate' => 'date:Y-m-d',
        'timesheetAutoLockEnabled' => 'boolean',
        'timesheetAutoLockDaysAfterPayDate' => 'integer',
        'timesheetUnlockStartDate' => 'date:Y-m-d',
        'timesheetUnlockEndDate' => 'date:Y-m-d',
    ];

    public static function current(): self
    {
        $setting = static::query()->first();

        if ($setting) {
            return $setting;
        }

        return static::query()->create([
            'incomeTaxRate' => (float) config('payroll.income_tax_rate', 0.25),
            'secondReliefAmount' => (float) config('payroll.second_relief_amount', 100),
            'postVacationPayToVacationAccount' => true,
        ]);
    }

    public static function incomeTaxRate(): float
    {
        return (float) static::current()->incomeTaxRate;
    }

    public static function secondReliefAmount(): float
    {
        return (float) static::current()->secondReliefAmount;
    }

    public static function postVacationPayToVacationAccount(): bool
    {
        return (bool) (static::current()->postVacationPayToVacationAccount ?? true);
    }
}
