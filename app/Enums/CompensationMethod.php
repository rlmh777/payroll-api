<?php

namespace App\Enums;

enum CompensationMethod: string
{
    case HourlyNoOt = 'HOURLY_NO_OT';
    case HourlyOt = 'HOURLY_OT';
    case BaseNoOt = 'BASE_NO_OT';
    case BaseOt = 'BASE_OT';
    case DailyRate = 'DAILY_RATE';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }

    public static function fromStored(?string $value, ?bool $requiresClocking = null): self
    {
        $normalized = strtoupper(trim((string) ($value ?? '')));

        return match ($normalized) {
            'HOURLY_NO_OT' => self::HourlyNoOt,
            'HOURLY_OT', 'HOURLY' => self::HourlyOt,
            'BASE_NO_OT', 'SALARY_NO_CLOCK', 'BASE_SALARY' => self::BaseNoOt,
            'BASE_OT', 'WEEKLY_SALARY_OT' => self::BaseOt,
            'WEEKLY_SALARY' => self::BaseNoOt,
            'DAILY_RATE', 'DAY_RATE', 'TRIP_RATE', 'UNIT_RATE' => self::DailyRate,
            default => self::HourlyOt,
        };
    }

    public function isHourlyBased(): bool
    {
        return match ($this) {
            self::HourlyNoOt, self::HourlyOt => true,
            default => false,
        };
    }

    public function isBaseBased(): bool
    {
        return match ($this) {
            self::BaseNoOt, self::BaseOt => true,
            default => false,
        };
    }

    public function isDailyRateBased(): bool
    {
        return $this === self::DailyRate;
    }

    public function allowsOvertime(): bool
    {
        return match ($this) {
            self::HourlyOt, self::BaseOt => true,
            default => false,
        };
    }

    public function defaultRequiresClocking(): bool
    {
        return match ($this) {
            self::HourlyNoOt, self::HourlyOt, self::BaseOt => true,
            self::BaseNoOt, self::DailyRate => false,
        };
    }

    public function storedPayType(): string
    {
        return $this->value;
    }
}
