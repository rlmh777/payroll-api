<?php

namespace App\Enums;

enum OvernightShiftMode: string
{
    case SplitAtMidnight = 'SPLIT_AT_MIDNIGHT';
    case AttributeToClockInDay = 'ATTRIBUTE_TO_CLOCK_IN_DAY';
    case AttributeToClockOutDay = 'ATTRIBUTE_TO_CLOCK_OUT_DAY';

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
            'ATTRIBUTE_TO_CLOCK_IN_DAY' => self::AttributeToClockInDay,
            'ATTRIBUTE_TO_CLOCK_OUT_DAY' => self::AttributeToClockOutDay,
            default => self::SplitAtMidnight,
        };
    }

    public function splitsAtMidnight(): bool
    {
        return $this === self::SplitAtMidnight;
    }
}
