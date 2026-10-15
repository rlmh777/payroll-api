<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialSecurityContributionRule extends Model
{
    use HasUuids;

    protected $table = 'social_security_contribution_rule';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'code',
        'name',
        'description',
        'priority',
        'employee_contribution_method',
        'employer_contribution_method',
        'employee_fixed_weekly_amount',
        'employer_fixed_weekly_amount',
        'employee_rate',
        'employer_rate',
        'skip_tier_lookup',
        'conditions',
        'effective_from',
        'effective_to',
        'state',
    ];

    protected $casts = [
        'priority' => 'integer',
        'employee_fixed_weekly_amount' => 'decimal:2',
        'employer_fixed_weekly_amount' => 'decimal:2',
        'employee_rate' => 'decimal:2',
        'employer_rate' => 'decimal:2',
        'skip_tier_lookup' => 'boolean',
        'conditions' => 'array',
        'effective_from' => 'date:Y-m-d',
        'effective_to' => 'date:Y-m-d',
    ];

    public function payrolls(): HasMany
    {
        return $this->hasMany(Payroll::class, 'applied_ss_rule_id');
    }
}
