<?php

namespace Database\Seeders;

use App\Models\SocialSecurityContributionRule;
use Illuminate\Database\Seeder;

class SocialSecurityContributionRuleSeeder extends Seeder
{
    public function run(): void
    {
        SocialSecurityContributionRule::updateOrCreate(
            ['code' => 'SENIOR_OR_BENEFIT_EXEMPT'],
            [
                'name' => 'Senior / Benefit Recipient Exemption',
                'description' => 'Employees age 65+, or age 60-64 receiving SS benefits: no employee contribution; employer pays $2.60 weekly.',
                'priority' => 100,
                'employee_contribution_method' => 'NONE',
                'employer_contribution_method' => 'FIXED_WEEKLY',
                'employee_fixed_weekly_amount' => null,
                'employer_fixed_weekly_amount' => 2.60,
                'employee_rate' => null,
                'employer_rate' => null,
                'skip_tier_lookup' => true,
                'conditions' => [
                    'any' => [
                        ['field' => 'age_years', 'op' => '>=', 'value' => 65],
                        [
                            'all' => [
                                ['field' => 'age_years', 'op' => 'between', 'min' => 60, 'max' => 64],
                                ['field' => 'is_receiving_ss_benefit', 'op' => '=', 'value' => true],
                            ],
                        ],
                    ],
                ],
                'state' => 'active',
            ],
        );
    }
}
