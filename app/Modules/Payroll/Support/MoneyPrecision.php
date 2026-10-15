<?php

namespace App\Modules\Payroll\Support;

final class MoneyPrecision
{
    public const STORE_SCALE = 10;

    public const DISPLAY_SCALE = 2;

    public static function store(float|int|string|null $value): float
    {
        return round((float) ($value ?? 0), self::STORE_SCALE);
    }

    public static function display(float|int|string|null $value): float
    {
        return round((float) ($value ?? 0), self::DISPLAY_SCALE);
    }
}
