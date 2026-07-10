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

    public static function current(): self
    {
        $setting = static::query()->first();

        if ($setting) {
            return $setting;
        }

        return static::query()->create([
            'clockRoundOffMinutes' => (int) config('attendance.clock_round_off_minutes', 30),
            'scheduleComparisonSource' => config('attendance.schedule_comparison_source', ScheduleComparisonSource::Rounded->value),
        ]);
    }

    public function scheduleComparisonSourceEnum(): ScheduleComparisonSource
    {
        return ScheduleComparisonSource::fromStored($this->scheduleComparisonSource);
    }
}
