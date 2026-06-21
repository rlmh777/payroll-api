<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class SocialSecurity extends Model
{
    use HasUuids;

    protected $table = 'social_security';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'weeklyEarningsStartRange',
        'weeklyEarningsEndRange',
        'weeklyInsurableEarnings',
        'weeklyEmployeeContributions',
        'weeklyEmployerContributions',
        'weekyEmployeeContributionsRate',
        'weeklyEmployerContributionsRate',
        'maxWeeklyShortTermBenefit',
        'maxWeeklyPensions',
        'maxYearlyPension',
        'state',
    ];

    protected $casts = [
        'weeklyEarningsStartRange' => 'decimal:2',
        'weeklyEarningsEndRange' => 'decimal:2',
        'weeklyInsurableEarnings' => 'decimal:2',
        'weeklyEmployeeContributions' => 'decimal:2',
        'weeklyEmployerContributions' => 'decimal:2',
        'weekyEmployeeContributionsRate' => 'decimal:2',
        'weeklyEmployerContributionsRate' => 'decimal:2',
        'maxWeeklyShortTermBenefit' => 'decimal:2',
        'maxWeeklyPensions' => 'decimal:2',
        'maxYearlyPension' => 'decimal:2',
    ];
}

