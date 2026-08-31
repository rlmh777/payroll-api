<?php

namespace App\Enums;

enum TimesheetPunctualityStatus: string
{
    case Early = 'EARLY';
    case Late = 'LATE';
    case OnTime = 'ON_TIME';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }

    public static function fromStored(?string $value): ?self
    {
        $normalized = strtoupper(trim((string) ($value ?? '')));

        return match ($normalized) {
            'EARLY' => self::Early,
            'LATE' => self::Late,
            'ON_TIME' => self::OnTime,
            default => null,
        };
    }
}
