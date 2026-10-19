<?php

namespace App\Modules\Payroll\Services;

use App\Enums\CompensationMethod;
use App\Models\PayrollEarningCode;
use App\Models\Timesheet;
use Illuminate\Support\Collection;

class TimesheetEarningAllocator
{
    /**
     * Split one timesheet into earning-code hours and pay.
     *
     * Premium holiday rates (1.5, double time, triple time, and any other multiplier above 1)
     * apply only to hourly and daily pay. Base salary is paid at the straight daily rate and
     * posted to regular pay.
     *
     * @param Collection<int, PayrollEarningCode> $earningCodes
     * @return list<array{key: string, hours: float, amount: float}>
     */
    public function allocate(
        Timesheet $timesheet,
        Collection $earningCodes,
        ?float $holidayMultiplier,
        float $hourlyRate,
        float $dailyRate,
        float $standardDailyHours,
        float $otMultiplier,
    ): array {
        $method = CompensationMethod::fromStored($timesheet->payType);
        $paid = $timesheet->isPaid !== false;
        $hourlyRate = max(0, $hourlyRate);
        $lines = [];

        $regularHours = round(max(0, (float) ($timesheet->regularHours ?? 0)), 2);
        $overtimeHours = round(max(0, (float) ($timesheet->overtimeHours ?? 0)), 2);
        $storedHolidayHours = round(max(0, (float) ($timesheet->holidayHours ?? 0)), 2);

        if ($regularHours > 0) {
            $this->add(
                $lines,
                $this->sourceKey($earningCodes, PayrollEarningCode::SOURCE_TIMESHEET_REGULAR),
                $regularHours,
                $paid ? round($regularHours * $hourlyRate, 2) : 0.0,
            );
        }

        if ($overtimeHours > 0) {
            if ($method->allowsOvertime()) {
                $this->add(
                    $lines,
                    $this->sourceKey($earningCodes, PayrollEarningCode::SOURCE_TIMESHEET_OVERTIME),
                    $overtimeHours,
                    $paid ? round($overtimeHours * $hourlyRate * $otMultiplier, 2) : 0.0,
                );
            } else {
                $this->add(
                    $lines,
                    $this->sourceKey($earningCodes, PayrollEarningCode::SOURCE_TIMESHEET_REGULAR),
                    $overtimeHours,
                    $paid ? round($overtimeHours * $hourlyRate, 2) : 0.0,
                );
            }
        }

        if ($storedHolidayHours > 0 || $holidayMultiplier !== null) {
            $this->allocateHoliday(
                $lines,
                $earningCodes,
                $method,
                $storedHolidayHours,
                $holidayMultiplier,
                $hourlyRate,
                $dailyRate,
                $standardDailyHours,
                $paid,
            );
        }

        return array_values($lines);
    }

    /**
     * @param Collection<int, PayrollEarningCode> $earningCodes
     * @return list<array{key: string, code: string|null, name: string, source: string|null, payMultiplier: float|null}>
     */
    public function columnsFor(Collection $earningCodes): array
    {
        return $earningCodes
            ->filter(fn (PayrollEarningCode $code) => in_array($code->source, [
                PayrollEarningCode::SOURCE_TIMESHEET_REGULAR,
                PayrollEarningCode::SOURCE_TIMESHEET_OVERTIME,
                PayrollEarningCode::SOURCE_TIMESHEET_HOLIDAY,
                PayrollEarningCode::SOURCE_VACATION,
            ], true))
            ->sortBy(fn (PayrollEarningCode $code) => sprintf(
                '%05d-%08.2f-%s',
                (int) $code->sort_order,
                (float) ($code->pay_multiplier ?? 0),
                mb_strtolower((string) $code->name),
            ))
            ->map(fn (PayrollEarningCode $code) => $this->columnFromCode($code))
            ->values()
            ->all();
    }

    /**
     * @param list<array{key: string, code: string|null, name: string, source: string|null, payMultiplier: float|null}> $columns
     * @param Collection<int, PayrollEarningCode> $earningCodes
     */
    public function ensureHolidayColumn(array &$columns, Collection $earningCodes, float $multiplier): void
    {
        if ($multiplier <= 1.001) {
            return;
        }

        $key = $this->holidayKey($earningCodes, $multiplier);
        foreach ($columns as $column) {
            if ($column['key'] === $key) {
                return;
            }
        }

        $code = $this->holidayCode($earningCodes, $multiplier);
        $columns[] = $code
            ? $this->columnFromCode($code)
            : [
                'key' => $key,
                'code' => null,
                'name' => $this->multiplierName($multiplier),
                'source' => PayrollEarningCode::SOURCE_TIMESHEET_HOLIDAY,
                'payMultiplier' => round($multiplier, 2),
            ];
    }

    /**
     * @param array<string, array{key: string, hours: float, amount: float}> $lines
     * @param Collection<int, PayrollEarningCode> $earningCodes
     */
    private function allocateHoliday(
        array &$lines,
        Collection $earningCodes,
        CompensationMethod $method,
        float $storedHolidayHours,
        ?float $holidayMultiplier,
        float $hourlyRate,
        float $dailyRate,
        float $standardDailyHours,
        bool $paid,
    ): void {
        if ($storedHolidayHours <= 0) {
            return;
        }

        if ($method->isBaseBased() || ($holidayMultiplier !== null && $holidayMultiplier <= 1.001)) {
            $this->add(
                $lines,
                $this->sourceKey($earningCodes, PayrollEarningCode::SOURCE_TIMESHEET_REGULAR),
                $storedHolidayHours,
                $paid ? $this->baseHolidayPay($storedHolidayHours, $hourlyRate, $dailyRate, $standardDailyHours) : 0.0,
            );

            return;
        }

        $multiplier = $holidayMultiplier ?? 1.0;
        $clockedHours = $storedHolidayHours;
        if ($multiplier > 1.001) {
            $clockedHours = round($storedHolidayHours / $multiplier, 2);
        }

        if ($multiplier <= 1.001 || ! $this->receivesPremium($method)) {
            $this->add(
                $lines,
                $this->sourceKey($earningCodes, PayrollEarningCode::SOURCE_TIMESHEET_REGULAR),
                $clockedHours,
                $paid ? round($clockedHours * $hourlyRate, 2) : 0.0,
            );

            return;
        }

        $this->add(
            $lines,
            $this->holidayKey($earningCodes, $multiplier),
            $clockedHours,
            $paid ? round($clockedHours * $hourlyRate * $multiplier, 2) : 0.0,
        );
    }

    private function receivesPremium(CompensationMethod $method): bool
    {
        return $method->isHourlyBased() || $method->isDailyRateBased();
    }

    private function baseHolidayPay(
        float $hours,
        float $hourlyRate,
        float $dailyRate,
        float $standardDailyHours,
    ): float {
        if ($dailyRate > 0) {
            if ($standardDailyHours > 0 && $hours > 0 && $hours < $standardDailyHours) {
                return round($dailyRate * ($hours / $standardDailyHours), 2);
            }

            return round($dailyRate, 2);
        }

        return round($hours * $hourlyRate, 2);
    }

    /**
     * @param array<string, array{key: string, hours: float, amount: float}> $lines
     */
    private function add(array &$lines, string $key, float $hours, float $amount): void
    {
        if (! isset($lines[$key])) {
            $lines[$key] = ['key' => $key, 'hours' => 0.0, 'amount' => 0.0];
        }

        $lines[$key]['hours'] = round($lines[$key]['hours'] + $hours, 2);
        $lines[$key]['amount'] = round($lines[$key]['amount'] + $amount, 2);
    }

    /**
     * @param Collection<int, PayrollEarningCode> $earningCodes
     */
    private function sourceKey(Collection $earningCodes, string $source): string
    {
        $code = $earningCodes->first(
            fn (PayrollEarningCode $item) => $item->source === $source && $item->pay_multiplier === null,
        ) ?? $earningCodes->first(fn (PayrollEarningCode $item) => $item->source === $source);

        return $code ? $this->columnKey($code) : 'source:'.$source;
    }

    /**
     * @param Collection<int, PayrollEarningCode> $earningCodes
     */
    private function holidayKey(Collection $earningCodes, float $multiplier): string
    {
        $code = $this->holidayCode($earningCodes, $multiplier);

        return $code ? $this->columnKey($code) : 'holiday:'.$this->multiplierToken($multiplier);
    }

    /**
     * @param Collection<int, PayrollEarningCode> $earningCodes
     */
    private function holidayCode(Collection $earningCodes, float $multiplier): ?PayrollEarningCode
    {
        return $earningCodes->first(function (PayrollEarningCode $code) use ($multiplier) {
            return $code->source === PayrollEarningCode::SOURCE_TIMESHEET_HOLIDAY
                && $code->pay_multiplier !== null
                && abs((float) $code->pay_multiplier - $multiplier) < 0.001;
        });
    }

    private function columnKey(PayrollEarningCode $code): string
    {
        return 'earning:'.$code->id;
    }

    /**
     * @return array{key: string, code: string|null, name: string, source: string|null, payMultiplier: float|null}
     */
    private function columnFromCode(PayrollEarningCode $code): array
    {
        return [
            'key' => $this->columnKey($code),
            'code' => $code->code,
            'name' => (string) $code->name,
            'source' => $code->source,
            'payMultiplier' => $code->pay_multiplier !== null ? round((float) $code->pay_multiplier, 2) : null,
        ];
    }

    private function multiplierName(float $multiplier): string
    {
        if (abs($multiplier - 1.5) < 0.001) {
            return 'Holiday Pay (1.5 time)';
        }
        if (abs($multiplier - 2.0) < 0.001) {
            return 'Holiday Pay (double time)';
        }
        if (abs($multiplier - 3.0) < 0.001) {
            return 'Holiday Pay (triple time)';
        }

        return 'Holiday Pay ('.$this->multiplierToken($multiplier).' time)';
    }

    private function multiplierToken(float $multiplier): string
    {
        $formatted = number_format($multiplier, 2, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }
}
