<?php

namespace App\Modules\Hr\Services\Employment;

use App\Enums\CompensationMethod;
use App\Models\EmployeeCompensation;
use App\Modules\Payroll\Services\PayrateFrequencyHelper;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class EmployeeCompensationResolver
{
    public const DEFAULT_STANDARD_WEEKLY_HOURS = 40.0;

    public const SALARY_HOURS_PER_YEAR = 2080;

    /**
     * @param Collection<int, EmployeeCompensation> $records
     */
    public function compensationForDate(
        Collection $records,
        Carbon $date,
        ?string $employmentDetailId = null,
    ): ?EmployeeCompensation
    {
        if ($employmentDetailId) {
            $contractRecords = $records->filter(
                fn (EmployeeCompensation $record) => (string) $record->employmentDetailId === $employmentDetailId
            );

            if ($contractRecords->isNotEmpty()) {
                $records = $contractRecords;
            }
        }

        $matching = $records->filter(function (EmployeeCompensation $record) use ($date) {
            $startDate = Carbon::parse($record->effectiveDate)->startOfDay();
            $endDate = $record->endDate ? Carbon::parse($record->endDate)->startOfDay() : null;

            return $startDate->lte($date) && (!$endDate || $endDate->gte($date));
        });

        return $matching
            ->sort(function (EmployeeCompensation $left, EmployeeCompensation $right) {
                if ($left->isActive !== $right->isActive) {
                    return $right->isActive <=> $left->isActive;
                }

                return Carbon::parse($right->effectiveDate)->timestamp
                    <=> Carbon::parse($left->effectiveDate)->timestamp;
            })
            ->first();
    }

    public function method(?EmployeeCompensation $compensation): CompensationMethod
    {
        if (!$compensation) {
            return CompensationMethod::HourlyOt;
        }

        return CompensationMethod::fromStored(
            $compensation->compensationMethod,
            $compensation->requiresClocking,
        );
    }

    public function requiresClocking(?EmployeeCompensation $compensation): bool
    {
        if (!$compensation) {
            return true;
        }

        if ($compensation->requiresClocking !== null) {
            return (bool) $compensation->requiresClocking;
        }

        return $this->method($compensation)->defaultRequiresClocking();
    }

    public function shouldAutoFillScheduledTimesheets(?EmployeeCompensation $compensation): bool
    {
        if (!$compensation) {
            return false;
        }

        $method = $this->method($compensation);
        if ($method->isDailyRateBased()) {
            return false;
        }

        if ($method->isBaseBased()) {
            return true;
        }

        return ! $this->requiresClocking($compensation);
    }

    public function payType(?EmployeeCompensation $compensation): string
    {
        return $this->method($compensation)->storedPayType();
    }

    public function standardWeeklyHours(?EmployeeCompensation $compensation): float
    {
        if (!$compensation) {
            return self::DEFAULT_STANDARD_WEEKLY_HOURS;
        }

        $hours = (float) ($compensation->standardWeeklyHours ?? 0);

        return $hours > 0 ? $hours : self::DEFAULT_STANDARD_WEEKLY_HOURS;
    }

    public function standardAnnualHours(?EmployeeCompensation $compensation): float
    {
        return round($this->standardWeeklyHours($compensation) * 52, 2);
    }

    public function derivedYearlyRateFromHourly(float $hourlyRate, ?EmployeeCompensation $compensation = null): ?float
    {
        if ($hourlyRate <= 0) {
            return null;
        }

        return round($hourlyRate * $this->standardAnnualHours($compensation), 2);
    }

    public function derivedWeeklyRateFromHourly(float $hourlyRate, ?EmployeeCompensation $compensation = null): ?float
    {
        if ($hourlyRate <= 0) {
            return null;
        }

        return round($hourlyRate * $this->standardWeeklyHours($compensation), 2);
    }

    public function effectiveYearlyRate(?EmployeeCompensation $compensation): ?float
    {
        if (!$compensation) {
            return null;
        }

        $yearlyRate = (float) $compensation->yearlyRate;
        if ($yearlyRate > 0) {
            return $yearlyRate;
        }

        return $this->derivedYearlyRateFromHourly((float) $compensation->hourlyRate, $compensation);
    }

    public function derivedHourlyRateFromYearly(float $yearlyRate, ?EmployeeCompensation $compensation = null): ?float
    {
        if ($yearlyRate <= 0) {
            return null;
        }

        return round($yearlyRate / $this->standardAnnualHours($compensation), 2);
    }

    public function derivedMonthlyRateFromYearly(float $yearlyRate): ?float
    {
        if ($yearlyRate <= 0) {
            return null;
        }

        return round($yearlyRate / 12, 2);
    }

    public function flatPeriodBasePay(
        ?EmployeeCompensation $compensation,
        ?int $payrateFrequencyId,
    ): ?float {
        if (!$compensation) {
            return null;
        }

        $method = $this->method($compensation);
        if ($method->isDailyRateBased() || $method->allowsOvertime()) {
            return null;
        }

        $yearlyRate = (float) ($this->effectiveYearlyRate($compensation) ?? 0);
        if ($yearlyRate <= 0) {
            return null;
        }

        $periodsPerYear = PayrateFrequencyHelper::periodsPerYear($payrateFrequencyId);
        if ($periodsPerYear === null) {
            return null;
        }

        return round($yearlyRate / $periodsPerYear, 2);
    }

    public function usesFlatPeriodBasePay(
        ?EmployeeCompensation $compensation,
        ?int $payrateFrequencyId,
    ): bool {
        return $this->flatPeriodBasePay($compensation, $payrateFrequencyId) !== null;
    }

    public function hourlyRateFromBaseSalary(float $baseSalary, ?float $standardWeeklyHours = null): float
    {
        $weeklyHours = $standardWeeklyHours !== null && $standardWeeklyHours > 0
            ? $standardWeeklyHours
            : self::DEFAULT_STANDARD_WEEKLY_HOURS;

        return round($baseSalary / round($weeklyHours * 52, 2), 2);
    }

    public function effectiveHourlyRate(?EmployeeCompensation $compensation): ?float
    {
        if (!$compensation) {
            return null;
        }

        $method = $this->method($compensation);
        $hourlyRate = (float) $compensation->hourlyRate;

        if ($method->isHourlyBased() && $hourlyRate > 0) {
            return $hourlyRate;
        }

        if ($hourlyRate > 0) {
            return $hourlyRate;
        }

        return $this->derivedHourlyRateFromYearly((float) $compensation->yearlyRate, $compensation);
    }

    /**
     * @return array{
     *   employeeCompensationId:?string,
     *   payType:string,
     *   requiresClocking:bool,
     *   hourlyRate:?float,
     *   weeklySalary:null,
     *   baseSalary:?float,
     *   dailyRate:?float,
     *   standardWeeklyHours:?float
     * }
     */
    public function payrollSnapshot(?EmployeeCompensation $compensation): array
    {
        $method = $this->method($compensation);
        $hourlyRate = $compensation ? (float) $compensation->hourlyRate : 0.0;
        $yearlyRate = $compensation ? (float) $compensation->yearlyRate : 0.0;
        $dailyRate = $compensation ? (float) ($compensation->dailyRate ?? 0) : 0.0;
        $standardWeeklyHours = $this->standardWeeklyHours($compensation);

        if ($method->isHourlyBased() && $yearlyRate <= 0 && $hourlyRate > 0) {
            $yearlyRate = (float) ($this->derivedYearlyRateFromHourly($hourlyRate, $compensation) ?? 0.0);
        }

        if ($method->isBaseBased() && $hourlyRate <= 0 && $yearlyRate > 0) {
            $hourlyRate = (float) ($this->derivedHourlyRateFromYearly($yearlyRate, $compensation) ?? 0.0);
        }

        if ($method->isDailyRateBased()) {
            $hourlyRate = 0.0;
            $yearlyRate = 0.0;
        }

        return [
            'employeeCompensationId' => $compensation?->id ? (string) $compensation->id : null,
            'payType' => $method->storedPayType(),
            'requiresClocking' => $this->requiresClocking($compensation),
            'hourlyRate' => $hourlyRate > 0 ? $hourlyRate : null,
            'weeklySalary' => null,
            'baseSalary' => $yearlyRate > 0 ? $yearlyRate : null,
            'dailyRate' => $dailyRate > 0 ? $dailyRate : null,
            'standardWeeklyHours' => $standardWeeklyHours,
        ];
    }

    public function effectiveDailyRate(?EmployeeCompensation $compensation): ?float
    {
        if (! $compensation) {
            return null;
        }

        $dailyRate = (float) ($compensation->dailyRate ?? 0);

        return $dailyRate > 0 ? $dailyRate : null;
    }
}
