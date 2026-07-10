<?php

namespace App\Enums;

enum OvertimeThresholdMode: string
{
    case Daily = 'DAILY';
    case Weekly = 'WEEKLY';
    case DailyAndWeekly = 'DAILY_AND_WEEKLY';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }

    public static function fromStored(?string $value): self
    {
        $normalized = strtoupper(trim((string) ($value ?? '')));

        return match ($normalized) {
            'WEEKLY' => self::Weekly,
            'DAILY' => self::Daily,
            'DAILY_AND_WEEKLY' => self::DailyAndWeekly,
            default => self::DailyAndWeekly,
        };
    }

    public function usesDailyThreshold(): bool
    {
        return $this === self::Daily || $this === self::DailyAndWeekly;
    }

    public function usesWeeklyThreshold(): bool
    {
        return $this === self::Weekly || $this === self::DailyAndWeekly;
    }
}
