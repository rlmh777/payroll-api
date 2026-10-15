<?php

namespace App\Enums;

enum ScheduleComparisonSource: string
{
    case Clock = 'CLOCK';
    case Rounded = 'ROUNDED';

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
            'CLOCK' => self::Clock,
            default => self::Rounded,
        };
    }

    public function usesRoundedTimes(): bool
    {
        return $this === self::Rounded;
    }
}
