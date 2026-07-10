<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollSetting extends Model
{
    protected $table = 'payroll_setting';

    protected $fillable = [
        'incomeTaxRate',
        'secondReliefAmount',
        'timesheetUnlockStartDate',
        'timesheetUnlockEndDate',
    ];

    protected $casts = [
        'incomeTaxRate' => 'decimal:4',
        'secondReliefAmount' => 'decimal:2',
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
}
