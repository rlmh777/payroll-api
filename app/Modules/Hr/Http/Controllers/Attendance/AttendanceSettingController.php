<?php

namespace App\Modules\Hr\Http\Controllers\Attendance;

use App\Enums\ScheduleComparisonSource;
use App\Http\Controllers\Controller;
use App\Models\AttendanceSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AttendanceSettingController extends Controller
{
    public function show(): JsonResponse
    {
        $setting = AttendanceSetting::current();

        return response()->json($this->formatSetting($setting));
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'clockRoundOffMinutes' => ['required', 'integer', 'min:1', 'max:120'],
            'scheduleComparisonSource' => ['sometimes', 'required', 'string', Rule::in(ScheduleComparisonSource::values())],
        ]);

        $setting = AttendanceSetting::current();
        $setting->update($validated);
        AttendanceSetting::clearCurrentCache();
        $setting = AttendanceSetting::current();

        return response()->json([
            'message' => 'Attendance settings updated.',
            'data' => $this->formatSetting($setting),
        ]);
    }

    /**
     * @return array{
     *   clockRoundOffMinutes:int,
     *   scheduleComparisonSource:string
     * }
     */
    private function formatSetting(AttendanceSetting $setting): array
    {
        return [
            'clockRoundOffMinutes' => $setting->clockRoundOffMinutes,
            'scheduleComparisonSource' => $setting->scheduleComparisonSourceEnum()->value,
        ];
    }
}
