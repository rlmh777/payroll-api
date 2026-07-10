<?php

namespace App\Enums;

enum LeaveAccrualMethod: string
{
    case Upfront = 'UPFRONT';
    case Monthly = 'MONTHLY';
    case None = 'NONE';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function fromStored(?string $value): self
    {
        return match (strtoupper((string) $value)) {
            'MONTHLY' => self::Monthly,
            'NONE' => self::None,
            default => self::Upfront,
        };
    }
}
