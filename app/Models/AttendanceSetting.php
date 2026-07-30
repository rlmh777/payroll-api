<?php

namespace App\Models;

use App\Enums\ScheduleComparisonSource;
use Illuminate\Database\Eloquent\Model;

class AttendanceSetting extends Model
{
    protected $table = 'attendance_setting';

    protected $fillable = [
        'clockRoundOffMinutes',
        'scheduleComparisonSource',
    ];

    protected $casts = [
        'clockRoundOffMinutes' => 'integer',
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
            'clockRoundOffMinutes' => (int) config('attendance.clock_round_off_minutes', 30),
            'scheduleComparisonSource' => config('attendance.schedule_comparison_source', ScheduleComparisonSource::Rounded->value),
        ]);
    }

    public static function clearCurrentCache(): void
    {
        self::$cachedCurrent = null;
    }

    public function scheduleComparisonSourceEnum(): ScheduleComparisonSource
    {
        return ScheduleComparisonSource::fromStored($this->scheduleComparisonSource);
    }
}
