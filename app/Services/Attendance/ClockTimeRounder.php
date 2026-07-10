<?php

namespace App\Services\Attendance;

use Carbon\Carbon;

class ClockTimeRounder
{
    public function roundClockIn(Carbon $time, int $roundOffMinutes): Carbon
    {
        return $this->roundUpToInterval($time, $roundOffMinutes);
    }

    public function roundClockOut(Carbon $time, int $roundOffMinutes): Carbon
    {
        return $this->roundUpToInterval($time, $roundOffMinutes);
    }

    private function roundUpToInterval(Carbon $time, int $roundOffMinutes): Carbon
    {
        $interval = max(1, $roundOffMinutes);
        $rounded = $time->copy()->second(0);
        $minute = (int) $rounded->minute;
        $remainder = $minute % $interval;

        if ($remainder === 0) {
            return $rounded;
        }

        $roundedMinute = $minute + ($interval - $remainder);

        if ($roundedMinute >= 60) {
            return $rounded->addHour()->minute($roundedMinute - 60);
        }

        return $rounded->minute($roundedMinute);
    }
}
