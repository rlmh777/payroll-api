<?php

namespace App\Models;

use App\Enums\BirthdayVisibility;
use Illuminate\Database\Eloquent\Model;

class HrSetting extends Model
{
    protected $table = 'hr_setting';

    protected $fillable = [
        'birthday_visibility',
        'contract_expiry_enabled',
        'contract_expiry_offsets',
    ];

    protected $casts = [
        'contract_expiry_enabled' => 'boolean',
        'contract_expiry_offsets' => 'array',
    ];

    private static ?self $cachedCurrent = null;

    public static function current(): self
    {
        if (self::$cachedCurrent !== null) {
            return self::$cachedCurrent;
        }

        $setting = static::query()->first();

        if ($setting) {
            return self::$cachedCurrent = $setting;
        }

        return self::$cachedCurrent = static::query()->create([
            'birthday_visibility' => BirthdayVisibility::Company->value,
            'contract_expiry_enabled' => true,
            'contract_expiry_offsets' => self::defaultOffsets(),
        ]);
    }

    public static function clearCurrentCache(): void
    {
        self::$cachedCurrent = null;
    }

    public function birthdayVisibility(): BirthdayVisibility
    {
        return BirthdayVisibility::tryFrom((string) $this->birthday_visibility)
            ?? BirthdayVisibility::Company;
    }

    public function contractExpiryEnabled(): bool
    {
        return $this->contract_expiry_enabled !== false;
    }

    /**
     * @return list<array{value: int, unit: string}>
     */
    public function contractExpiryOffsets(): array
    {
        return self::normalizeOffsets(is_array($this->contract_expiry_offsets) ? $this->contract_expiry_offsets : []);
    }

    /**
     * @return list<array{value: int, unit: string}>
     */
    public static function defaultOffsets(): array
    {
        return [
            ['value' => 3, 'unit' => 'months'],
            ['value' => 1, 'unit' => 'months'],
            ['value' => 1, 'unit' => 'weeks'],
            ['value' => 1, 'unit' => 'days'],
        ];
    }

    /**
     * @param  list<array{value?: mixed, unit?: mixed}>  $offsets
     * @return list<array{value: int, unit: string}>
     */
    public static function normalizeOffsets(array $offsets): array
    {
        $normalized = [];
        $seen = [];

        foreach ($offsets as $offset) {
            if (! is_array($offset)) {
                continue;
            }
            $value = (int) ($offset['value'] ?? 0);
            $unit = strtolower((string) ($offset['unit'] ?? 'days'));
            if ($value < 1 || $value > 36 || ! in_array($unit, ['days', 'weeks', 'months'], true)) {
                continue;
            }
            $key = $value.'-'.$unit;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $normalized[] = ['value' => $value, 'unit' => $unit];
        }

        return $normalized !== [] ? array_values($normalized) : self::defaultOffsets();
    }

    /**
     * @param  array{value: int, unit: string}  $offset
     */
    public static function offsetLabel(array $offset): string
    {
        $value = (int) $offset['value'];
        $unit = (string) $offset['unit'];
        $singular = match ($unit) {
            'months' => 'month',
            'weeks' => 'week',
            default => 'day',
        };

        return $value === 1 ? "1 {$singular}" : "{$value} {$unit}";
    }
}
