<?php

namespace App\Services\Attendance;

use App\Models\PublicHoliday;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class PublicHolidayPayResolver
{
    /** @var array<string, float>|null */
    private ?array $multipliersByDate = null;

    /**
     * @return array<string, float>
     */
    public function multipliersForRange(CarbonInterface $rangeStart, CarbonInterface $rangeEnd): array
    {
        $holidays = PublicHoliday::query()
            ->where('isActive', true)
            ->whereDate('startDate', '<=', $rangeEnd->toDateString())
            ->whereDate('endDate', '>=', $rangeStart->toDateString())
            ->get();

        $dates = [];

        foreach ($holidays as $holiday) {
            $date = Carbon::parse($holiday->startDate)->max($rangeStart)->startOfDay();
            $endDate = Carbon::parse($holiday->endDate)->min($rangeEnd)->startOfDay();
            $multiplier = max(0, (float) ($holiday->payMultiplier ?? 1.5));

            while ($date->lte($endDate)) {
                $key = $date->toDateString();
                $dates[$key] = isset($dates[$key]) ? max($dates[$key], $multiplier) : $multiplier;
                $date->addDay();
            }
        }

        return $dates;
    }

    public function payMultiplierForDate(CarbonInterface|string $date): ?float
    {
        $dateString = $date instanceof CarbonInterface
            ? $date->toDateString()
            : Carbon::parse($date)->toDateString();

        if ($this->multipliersByDate !== null && array_key_exists($dateString, $this->multipliersByDate)) {
            return $this->multipliersByDate[$dateString];
        }

        $holiday = PublicHoliday::query()
            ->where('isActive', true)
            ->whereDate('startDate', '<=', $dateString)
            ->whereDate('endDate', '>=', $dateString)
            ->orderByDesc('payMultiplier')
            ->first();

        if (!$holiday) {
            return null;
        }

        return max(0, (float) ($holiday->payMultiplier ?? 1.5));
    }

    public function isHoliday(CarbonInterface|string $date): bool
    {
        return $this->payMultiplierForDate($date) !== null;
    }

    public function payableHolidayHours(float $hoursWorked, CarbonInterface|string $date): float
    {
        $multiplier = $this->payMultiplierForDate($date);

        if ($multiplier === null || $hoursWorked <= 0) {
            return 0.0;
        }

        return round($hoursWorked * $multiplier, 2);
    }

    /**
     * @param array<string, float> $multipliersByDate
     */
    public function primeCache(array $multipliersByDate): void
    {
        $this->multipliersByDate = $multipliersByDate;
    }

    public function clearCache(): void
    {
        $this->multipliersByDate = null;
    }
}
