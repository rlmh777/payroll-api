<?php

namespace App\Modules\Hr\Services\Attendance;

use Carbon\Carbon;

class ClockTimeRounder
{
    public function roundClockIn(Carbon $time, int $roundOffMinutes): Carbon
    {
        return $this->roundToNearestInterval($time, $roundOffMinutes);
    }

    public function roundClockOut(Carbon $time, int $roundOffMinutes): Carbon
    {
        return $this->roundToNearestInterval($time, $roundOffMinutes);
    }

    private function roundToNearestInterval(Carbon $time, int $roundOffMinutes): Carbon
    {
        $intervalMinutes = max(1, $roundOffMinutes);
        $intervalSeconds = $intervalMinutes * 60;
        $rounded = $time->copy();
        $secondsIntoHour = ((int) $rounded->minute * 60) + (int) $rounded->second;
        $remainder = $secondsIntoHour % $intervalSeconds;

        if ($remainder === 0) {
            return $rounded->second(0);
        }

        if ($remainder * 2 < $intervalSeconds) {
            return $rounded->subSeconds($remainder)->second(0);
        }

        return $rounded->addSeconds($intervalSeconds - $remainder)->second(0);
    }
}
