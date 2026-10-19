<?php

namespace App\Models;

use App\Enums\BirthdayVisibility;
use Illuminate\Database\Eloquent\Model;

class HrSetting extends Model
{
    protected $table = 'hr_setting';

    public const PROFILE_CHANGE_SUPERVISOR = 'supervisor';

    public const PROFILE_CHANGE_HR = 'hr';

    protected $fillable = [
        'birthday_visibility',
        'contract_expiry_enabled',
        'contract_expiry_offsets',
        'appraisal_reminder_enabled',
        'appraisal_reminder_interval_value',
        'appraisal_reminder_interval_unit',
        'profile_change_approval',
    ];

    protected $casts = [
        'contract_expiry_enabled' => 'boolean',
        'contract_expiry_offsets' => 'array',
        'appraisal_reminder_enabled' => 'boolean',
        'appraisal_reminder_interval_value' => 'integer',
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
            'appraisal_reminder_enabled' => true,
            'appraisal_reminder_interval_value' => 2,
            'appraisal_reminder_interval_unit' => 'days',
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

    public function appraisalReminderEnabled(): bool
    {
        return $this->appraisal_reminder_enabled !== false;
    }

    /**
    public function profileChangeApproval(): string
    {
        $value = (string) $this->profile_change_approval;

        return in_array($value, [self::PROFILE_CHANGE_SUPERVISOR, self::PROFILE_CHANGE_HR], true)
            ? $value
            : self::PROFILE_CHANGE_HR;
    }

    public function profileChangeRequiresHr(): bool
    {
        return $this->profileChangeApproval() === self::PROFILE_CHANGE_HR;
    }

     * @return array{value: int, unit: string}
     */
    public function appraisalReminderInterval(): array
    {
        return self::normalizeReminderInterval(
            (int) ($this->appraisal_reminder_interval_value ?: 2),
            (string) ($this->appraisal_reminder_interval_unit ?: 'days'),
        );
    }

    /**
     * @return array{value: int, unit: string}
     */
    public static function normalizeReminderInterval(int $value, string $unit): array
    {
        $unit = strtolower($unit);
        if (! in_array($unit, ['days', 'weeks'], true)) {
            $unit = 'days';
        }
        if ($value < 1) {
            $value = 1;
        }
        if ($value > 30) {
            $value = 30;
        }

        return ['value' => $value, 'unit' => $unit];
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
