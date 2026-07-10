<?php

namespace App\Enums;

enum CompensationReason: string
{
    case Initial = 'INITIAL';
    case Increment = 'INCREMENT';
    case Promotion = 'PROMOTION';
    case Evaluation = 'EVALUATION';
    case Correction = 'CORRECTION';
    case Other = 'OTHER';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
