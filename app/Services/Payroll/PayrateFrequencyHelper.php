<?php

namespace App\Services\Payroll;

use App\Models\PayrateFrequency;

class PayrateFrequencyHelper
{
    public static function isMonthly(?int $frequencyId): bool
    {
        return self::normalizedName($frequencyId) === 'monthly';
    }

    public static function isBiweekly(?int $frequencyId): bool
    {
        return self::normalizedName($frequencyId) === 'biweekly';
    }

    public static function periodsPerYear(?int $frequencyId): ?int
    {
        return match (self::normalizedName($frequencyId)) {
            'monthly' => 12,
            'biweekly' => 26,
            default => null,
        };
    }

    private static function normalizedName(?int $frequencyId): ?string
    {
        if (!$frequencyId) {
            return null;
        }

        $name = PayrateFrequency::query()->whereKey($frequencyId)->value('name');

        if (!is_string($name) || trim($name) === '') {
            return null;
        }

        return strtolower(trim($name));
    }
}
