<?php

namespace App\Services\SocialSecurity;

use App\Models\Employee;
use App\Models\EmployeeSsBenefitStatus;
use App\Models\SocialSecurity;
use App\Models\SocialSecurityContributionRule;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class SocialSecurityContributionService
{
    public function __construct(
        private readonly SsRuleConditionEvaluator $conditionEvaluator,
    ) {
    }

    /**
     * @return array{
     *     employee_amount: float,
     *     employer_amount: float,
     *     applied_rule_id: string|null,
     *     applied_tier_id: string|null,
     *     detail: array<string, mixed>
     * }
     */
    public function calculate(
        Employee $employee,
        Carbon $asOfDate,
        float $weeklyInsurableEarnings,
        float $weeksInPeriod = 1.0,
    ): array {
        $context = $this->buildContext($employee, $asOfDate);
        $tier = $this->findTier($weeklyInsurableEarnings);
        $rule = $this->findMatchingRule($asOfDate, $context);

        if ($rule) {
            return $this->applyRule($rule, $tier, $weeklyInsurableEarnings, $weeksInPeriod, $context);
        }

        if (!$tier) {
            return [
                'employee_amount' => 0.0,
                'employer_amount' => 0.0,
                'applied_rule_id' => null,
                'applied_tier_id' => null,
                'detail' => [
                    'method' => 'none',
                    'context' => $context,
                    'weekly_insurable_earnings' => $weeklyInsurableEarnings,
                    'weeks_in_period' => $weeksInPeriod,
                ],
            ];
        }

        return [
            'employee_amount' => round((float) $tier->weeklyEmployeeContributions * $weeksInPeriod, 2),
            'employer_amount' => round((float) $tier->weeklyEmployerContributions * $weeksInPeriod, 2),
            'applied_rule_id' => null,
            'applied_tier_id' => $tier->id,
            'detail' => [
                'method' => 'tier_table',
                'context' => $context,
                'weekly_insurable_earnings' => $weeklyInsurableEarnings,
                'weeks_in_period' => $weeksInPeriod,
                'tier' => [
                    'id' => $tier->id,
                    'weeklyEarningsStartRange' => $tier->weeklyEarningsStartRange,
                    'weeklyEarningsEndRange' => $tier->weeklyEarningsEndRange,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildContext(Employee $employee, Carbon $asOfDate): array
    {
        $ageYears = null;
        if ($employee->birthdate) {
            $birthdate = Carbon::parse($employee->birthdate);
            $ageYears = (int) $birthdate->diffInYears($asOfDate);
        }

        $isReceivingBenefit = EmployeeSsBenefitStatus::query()
            ->where('employeeId', $employee->id)
            ->where('is_receiving_benefit', true)
            ->whereDate('effective_from', '<=', $asOfDate)
            ->where(function ($query) use ($asOfDate) {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $asOfDate);
            })
            ->exists();

        return [
            'age_years' => $ageYears,
            'is_receiving_ss_benefit' => $isReceivingBenefit,
            'as_of_date' => $asOfDate->toDateString(),
        ];
    }

    public function previewForEmployee(Employee $employee, Carbon $asOfDate, float $weeklyInsurableEarnings = 520): array
    {
        return $this->calculate($employee, $asOfDate, $weeklyInsurableEarnings, 1.0);
    }

    private function findTier(float $weeklyInsurableEarnings): ?SocialSecurity
    {
        return SocialSecurity::query()
            ->where('state', 'active')
            ->where('weeklyEarningsStartRange', '<=', $weeklyInsurableEarnings)
            ->where(function ($query) use ($weeklyInsurableEarnings) {
                $query->whereNull('weeklyEarningsEndRange')
                    ->orWhere('weeklyEarningsEndRange', '>=', $weeklyInsurableEarnings);
            })
            ->orderByDesc('weeklyEarningsStartRange')
            ->first();
    }

    /**
     * @param array<string, mixed> $context
     */
    private function findMatchingRule(Carbon $asOfDate, array $context): ?SocialSecurityContributionRule
    {
        /** @var Collection<int, SocialSecurityContributionRule> $rules */
        $rules = SocialSecurityContributionRule::query()
            ->where('state', 'active')
            ->where(function ($query) use ($asOfDate) {
                $query->whereNull('effective_from')
                    ->orWhereDate('effective_from', '<=', $asOfDate);
            })
            ->where(function ($query) use ($asOfDate) {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $asOfDate);
            })
            ->orderByDesc('priority')
            ->orderBy('created_at')
            ->get();

        foreach ($rules as $rule) {
            $conditions = $rule->conditions ?? [];
            if ($this->conditionEvaluator->matches($conditions, $context)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     * @return array{
     *     employee_amount: float,
     *     employer_amount: float,
     *     applied_rule_id: string|null,
     *     applied_tier_id: string|null,
     *     detail: array<string, mixed>
     * }
     */
    private function applyRule(
        SocialSecurityContributionRule $rule,
        ?SocialSecurity $tier,
        float $weeklyInsurableEarnings,
        float $weeksInPeriod,
        array $context,
    ): array {
        $tierForCalculation = $rule->skip_tier_lookup ? null : $tier;

        $employeeWeekly = $this->resolveWeeklyAmount(
            $rule->employee_contribution_method,
            $rule->employee_fixed_weekly_amount,
            $rule->employee_rate,
            $weeklyInsurableEarnings,
            $tierForCalculation,
            'employee',
        );

        $employerWeekly = $this->resolveWeeklyAmount(
            $rule->employer_contribution_method,
            $rule->employer_fixed_weekly_amount,
            $rule->employer_rate,
            $weeklyInsurableEarnings,
            $tierForCalculation,
            'employer',
        );

        return [
            'employee_amount' => round($employeeWeekly * $weeksInPeriod, 2),
            'employer_amount' => round($employerWeekly * $weeksInPeriod, 2),
            'applied_rule_id' => $rule->id,
            'applied_tier_id' => $tierForCalculation?->id,
            'detail' => [
                'method' => 'contribution_rule',
                'rule' => [
                    'id' => $rule->id,
                    'code' => $rule->code,
                    'name' => $rule->name,
                ],
                'context' => $context,
                'weekly_insurable_earnings' => $weeklyInsurableEarnings,
                'weeks_in_period' => $weeksInPeriod,
                'employee_weekly' => $employeeWeekly,
                'employer_weekly' => $employerWeekly,
                'tier' => $tierForCalculation ? [
                    'id' => $tierForCalculation->id,
                    'weeklyEarningsStartRange' => $tierForCalculation->weeklyEarningsStartRange,
                    'weeklyEarningsEndRange' => $tierForCalculation->weeklyEarningsEndRange,
                ] : null,
            ],
        ];
    }

    private function resolveWeeklyAmount(
        string $method,
        ?float $fixedWeekly,
        ?float $rate,
        float $weeklyInsurableEarnings,
        ?SocialSecurity $tier,
        string $party,
    ): float {
        return match ($method) {
            'NONE' => 0.0,
            'FIXED_WEEKLY' => (float) ($fixedWeekly ?? 0),
            'RATE' => round($weeklyInsurableEarnings * ((float) ($rate ?? 0) / 100), 2),
            'TIER_TABLE' => $tier
                ? (float) ($party === 'employee'
                    ? $tier->weeklyEmployeeContributions
                    : $tier->weeklyEmployerContributions)
                : 0.0,
            default => 0.0,
        };
    }
}
