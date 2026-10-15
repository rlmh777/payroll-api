<?php

namespace App\Modules\Hr\Services\Attendance;

use App\Models\ScheduledWork;
use App\Models\ShiftTemplate;
use App\Models\Timesheet;
use App\Modules\Hr\Services\Employment\EmploymentContractAssignmentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShiftTemplateAssignmentService
{
    public function __construct(
        private readonly ScheduledWorkOverlapValidator $overlapValidator,
        private readonly TimesheetCompensationRecalculationService $timesheetRecalculationService,
        private readonly EmploymentContractAssignmentService $contractAssignmentService,
    ) {
    }

    /**
     * @return list<ScheduledWork>
     *
     * @throws ValidationException
     */
    public function assign(
        ShiftTemplate $template,
        string $employeeId,
        string $date,
        ?string $endDate = null,
        ?string $employmentDetailId = null,
        ?int $departmentId = null,
        ?int $worksiteId = null,
        ?string $description = null,
    ): array {
        $segments = $template->resolvedSegments();
        if ($segments === []) {
            throw ValidationException::withMessages([
                'segments' => ['This shift template has no time segments.'],
            ]);
        }

        $startDate = Carbon::parse($date)->toDateString();
        $endDate = Carbon::parse($endDate ?: $date)->toDateString();
        if ($endDate < $startDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        $today = Carbon::today()->toDateString();
        if ($endDate < $today) {
            throw ValidationException::withMessages([
                'date' => ['Only today and future dates can be scheduled.'],
            ]);
        }
        if ($startDate < $today) {
            $startDate = $today;
        }

        $label = $description !== null && trim($description) !== ''
            ? trim($description)
            : $template->displayLabel();

        return DB::transaction(function () use (
            $template,
            $segments,
            $employeeId,
            $startDate,
            $endDate,
            $employmentDetailId,
            $departmentId,
            $worksiteId,
            $label,
        ) {
            $created = [];

            foreach ($segments as $index => $segment) {
                $includeLunch = $template->include_lunch_hour && $index === 0;
                $payload = [
                    'startDate' => $startDate,
                    'endDate' => $endDate,
                    'startTime' => $segment['start_time'],
                    'endTime' => $segment['end_time'],
                    'employeeId' => $employeeId,
                    'employmentDetailId' => $employmentDetailId,
                    'departmentId' => $departmentId,
                    'worksiteId' => $worksiteId,
                    'includeLunchHour' => $includeLunch,
                    'lunchHourHours' => $includeLunch
                        ? (float) ($template->lunch_hour_hours ?: 1)
                        : 1,
                    'description' => $label,
                    'rate' => 1,
                ];

                $contractId = $this->contractAssignmentService->resolveContractId(
                    $payload['employeeId'],
                    $payload['employmentDetailId'] ?? null,
                    isset($payload['departmentId']) ? (int) $payload['departmentId'] : null,
                    $payload['startDate'],
                    $payload['endDate'],
                );
                if ($contractId) {
                    $payload['employmentDetailId'] = $contractId;
                }

                $this->overlapValidator->validate(
                    $payload['employeeId'],
                    $payload['startDate'],
                    $payload['endDate'],
                    $payload['startTime'],
                    $payload['endTime'],
                );

                $created[] = ScheduledWork::create($payload);
            }

            $this->recalculateImpact($employeeId, $departmentId, $startDate, $endDate);

            return $created;
        });
    }

    private function recalculateImpact(
        ?string $employeeId,
        ?int $departmentId,
        string $startDate,
        string $endDate,
    ): void {
        $employeeIds = [];
        $allActive = false;

        if ($employeeId) {
            $employeeIds[] = $employeeId;
        } elseif ($departmentId) {
            $employeeIds = Timesheet::query()
                ->where('departmentId', $departmentId)
                ->whereDate('date', '>=', $startDate)
                ->whereDate('date', '<=', $endDate)
                ->pluck('employeeId')
                ->filter()
                ->map(fn ($id) => (string) $id)
                ->unique()
                ->values()
                ->all();
        } else {
            $allActive = true;
        }

        $this->timesheetRecalculationService->recalculate(
            $allActive ? [] : $employeeIds,
            $startDate,
            $endDate,
            $allActive,
        );
    }
}
