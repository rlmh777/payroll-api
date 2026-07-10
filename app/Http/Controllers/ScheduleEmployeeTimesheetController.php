<?php

namespace App\Http\Controllers;

use App\Models\ScheduleEmployeeTimesheet;
use App\Services\Attendance\TimesheetCompensationRecalculationService;
use App\Services\Scheduling\EmployeeScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class ScheduleEmployeeTimesheetController extends Controller
{
    private const RELATIONS = [
        'employee',
        'department',
        'employmentDetail.department',
        'employmentDetail.worksite',
        'employmentDetail.contractType',
        'employmentDetail.defaultPayPeriodGroup',
    ];

    public function __construct(
        private readonly EmployeeScheduleService $employeeScheduleService,
        private readonly TimesheetCompensationRecalculationService $timesheetRecalculationService,
    ) {
    }

    public function show(ScheduleEmployeeTimesheet $scheduleEmployeeTimesheet): JsonResponse
    {
        return response()->json(
            $scheduleEmployeeTimesheet->load(self::RELATIONS)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employeeId' => ['required', 'string', 'exists:employee,id'],
            'employmentDetailId' => ['nullable', 'uuid', 'exists:employment_detail,id'],
            'departmentId' => ['nullable', 'integer', 'exists:department,id'],
            'mode' => ['required', Rule::in(['single', 'series'])],
            'date' => ['required_if:mode,single', 'nullable', 'date'],
            'startDate' => ['required_if:mode,series', 'nullable', 'date'],
            'endDate' => ['required_if:mode,series', 'nullable', 'date', 'after_or_equal:startDate'],
            'days' => ['required_if:mode,series', 'nullable', 'array', 'min:1'],
            'days.*' => [Rule::in(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'])],
            'startTime' => ['required', 'date_format:H:i'],
            'endTime' => ['required', 'date_format:H:i'],
            'include_lunch_hour' => ['nullable', 'boolean'],
            'lunch_hour_hours' => ['nullable', 'numeric', 'min:0', 'max:8'],
        ]);

        if ($validated['endTime'] <= $validated['startTime']) {
            return response()->json(['error' => 'End time must be after start time.'], 422);
        }

        $result = $this->employeeScheduleService->create($validated);
        $this->recalculateScheduleRecords($result['records']);

        return response()->json([
            'message' => 'Schedule saved successfully',
            'series_id' => $result['series_id'],
            'data' => $result['records']->values(),
        ], 201);
    }

    public function update(Request $request, ScheduleEmployeeTimesheet $scheduleEmployeeTimesheet): JsonResponse
    {
        $validated = $request->validate([
            'scope' => ['required', Rule::in(['single', 'series'])],
            'employmentDetailId' => ['nullable', 'uuid', 'exists:employment_detail,id'],
            'departmentId' => ['nullable', 'integer', 'exists:department,id'],
            'startTime' => ['sometimes', 'date_format:H:i'],
            'endTime' => ['sometimes', 'date_format:H:i'],
            'include_lunch_hour' => ['nullable', 'boolean'],
            'lunch_hour_hours' => ['nullable', 'numeric', 'min:0', 'max:8'],
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date', 'after_or_equal:startDate'],
            'days' => ['nullable', 'array', 'min:1'],
            'days.*' => [Rule::in(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'])],
            'date' => ['nullable', 'date'],
        ]);

        if (
            isset($validated['startTime'], $validated['endTime'])
            && $validated['endTime'] <= $validated['startTime']
        ) {
            return response()->json(['error' => 'End time must be after start time.'], 422);
        }

        $before = $this->scheduleTargets($scheduleEmployeeTimesheet, $validated['scope'] ?? 'single');
        $records = $this->employeeScheduleService->update($scheduleEmployeeTimesheet, $validated);
        $this->recalculateScheduleRecords($before->merge($records));

        return response()->json([
            'message' => 'Schedule updated successfully',
            'data' => $records->values(),
        ]);
    }

    public function destroy(Request $request, ScheduleEmployeeTimesheet $scheduleEmployeeTimesheet): JsonResponse
    {
        $validated = $request->validate([
            'scope' => ['nullable', Rule::in(['single', 'series'])],
        ]);

        $before = $this->scheduleTargets($scheduleEmployeeTimesheet, $validated['scope'] ?? 'single');
        $deleted = $this->employeeScheduleService->delete(
            $scheduleEmployeeTimesheet,
            $validated['scope'] ?? 'single',
        );
        $this->recalculateScheduleRecords($before);

        return response()->json([
            'message' => 'Schedule deleted successfully',
            'deleted' => $deleted,
        ]);
    }

    public function copy(Request $request, ScheduleEmployeeTimesheet $scheduleEmployeeTimesheet): JsonResponse
    {
        $validated = $request->validate([
            'scope' => ['required', Rule::in(['single', 'series'])],
            'employeeIds' => ['required', 'array', 'min:1'],
            'employeeIds.*' => ['string', 'exists:employee,id'],
        ]);

        $records = $this->employeeScheduleService->copy($scheduleEmployeeTimesheet, $validated);
        $this->recalculateScheduleRecords($records);

        return response()->json([
            'message' => 'Schedule copied successfully',
            'data' => $records->values(),
        ], 201);
    }

    /**
     * @return Collection<int, ScheduleEmployeeTimesheet>
     */
    private function scheduleTargets(ScheduleEmployeeTimesheet $schedule, string $scope): Collection
    {
        if ($scope === 'series' && $schedule->series_id) {
            return ScheduleEmployeeTimesheet::query()
                ->where('series_id', $schedule->series_id)
                ->get();
        }

        return collect([$schedule]);
    }

    /**
     * @param iterable<int, ScheduleEmployeeTimesheet> $records
     */
    private function recalculateScheduleRecords(iterable $records): void
    {
        $collection = $records instanceof Collection ? $records : collect($records);
        $employeeIds = $collection
            ->pluck('employeeId')
            ->filter()
            ->map(fn ($employeeId) => (string) $employeeId)
            ->unique()
            ->values()
            ->all();
        $dates = $collection
            ->pluck('date')
            ->filter()
            ->map(fn ($date) => $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : (string) $date)
            ->sort()
            ->values();

        if ($employeeIds === [] || $dates->isEmpty()) {
            return;
        }

        $this->timesheetRecalculationService->recalculate(
            $employeeIds,
            $dates->first(),
            $dates->last(),
        );
    }
}
