<?php

namespace Database\Seeders;

use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\EmploymentDetail;
use App\Models\PublicHoliday;
use App\Models\Timesheet;
use App\Models\TimesheetTemplate;
use App\Models\TimesheetTemplateDepartment;
use App\Modules\Hr\Services\Attendance\ClockTimeRounder;
use App\Modules\Hr\Services\Attendance\LunchBreakHelper;
use App\Modules\Hr\Services\Attendance\TimesheetOvertimeAllocator;
use App\Modules\Hr\Services\Attendance\TimesheetRoundOffService;
use App\Modules\Hr\Services\Employment\EmployeeCompensationResolver;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class TimesheetSeeder extends Seeder
{
    public function run(): void
    {
        $employees = Employee::query()
            ->with(['timesheetTemplate', 'employmentDetails', 'employeeCompensations'])
            ->orderByPersonName('lastName', 'asc')
            ->orderBy('person.firstName', 'asc')
            ->get();

        if ($employees->isEmpty()) {
            $this->command?->warn('TimesheetSeeder skipped: no employees found.');

            return;
        }

        $endDate = Carbon::today()->subDay()->startOfDay();
        $startDate = Carbon::today()->subWeeks(2)->startOfDay();

        $roundOffMinutes = AttendanceSetting::current()->clockRoundOffMinutes;
        $rounder = app(ClockTimeRounder::class);
        $roundOffService = app(TimesheetRoundOffService::class);
        $overtimeAllocator = app(TimesheetOvertimeAllocator::class);
        $compensationResolver = app(EmployeeCompensationResolver::class);

        $templateAssignments = TimesheetTemplateDepartment::query()
            ->with('timesheetTemplate')
            ->orderByDesc('effective_date')
            ->get()
            ->groupBy(fn (TimesheetTemplateDepartment $assignment) => (string) $assignment->department_id);

        $holidayMultipliers = $this->holidayMultipliers($startDate, $endDate);
        $created = 0;

        foreach ($employees as $employeeIndex => $employee) {
            $employmentDetails = $employee->employmentDetails
                ->sortByDesc(fn (EmploymentDetail $detail) => sprintf(
                    '%d-%s',
                    $detail->isActive ? 1 : 0,
                    $detail->startDate?->format('Y-m-d') ?? '',
                ))
                ->values();

            for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
                $workDate = $date->toDateString();
                $employmentDetail = $this->employmentDetailForDate($employmentDetails, $date);

                if (!$employmentDetail) {
                    continue;
                }

                $timesheetTemplate = $this->timesheetTemplateForDate(
                    $employee,
                    $employmentDetail->departmentId,
                    $date,
                    $templateAssignments,
                );

                if (!$this->isScheduledWorkDay($timesheetTemplate, $date, $employmentDetail->departmentId)) {
                    continue;
                }

                $daySlots = $timesheetTemplate?->daySlotsFor($date->format('D'), $employmentDetail->departmentId) ?? [];
                if ($daySlots === []) {
                    $daySlots = [[
                        'start_time' => '08:00',
                        'end_time' => '17:00',
                        'include_lunch_hour' => true,
                    ]];
                }

                $holidayPayMultiplier = $holidayMultipliers[$workDate] ?? null;
                $compensation = $compensationResolver->compensationForDate(
                    $employee->employeeCompensations,
                    $date,
                );
                $payrollSnapshot = $compensationResolver->payrollSnapshot($compensation);
                $payType = $payrollSnapshot['payType'];
                $scheduledHours = $this->scheduledHoursForDate(
                    $timesheetTemplate,
                    $date,
                    $employmentDetail->departmentId,
                );
                $holidayHours = $holidayPayMultiplier !== null
                    ? round($scheduledHours * $holidayPayMultiplier, 2)
                    : 0.0;
                $approvalStatus = (($employeeIndex + (int) $date->format('z')) % 3) === 0 ? 'PENDING' : 'APPROVED';
                $seed = crc32($employee->id.$workDate);

                foreach ($daySlots as $slotIndex => $slot) {
                    $startTime = $slot['start_time'] ?? '08:00';
                    $endTime = $slot['end_time'] ?? '17:00';

                    $clockIn = Carbon::parse($workDate.' '.$startTime)
                        ->addMinutes((($seed + $slotIndex) % 25) - 10);
                    $clockOut = Carbon::parse($workDate.' '.$endTime)
                        ->addMinutes(($seed + $slotIndex) % 50);

                    if ($employeeIndex % 4 === 0 && count($daySlots) > 1) {
                        $clockOut = $clockIn->copy()->addHours(6);
                    }

                    $roundOffIn = $rounder->roundClockIn($clockIn, $roundOffMinutes);
                    $roundOffOut = $rounder->roundClockOut($clockOut, $roundOffMinutes);
                    $clockedHoursWorked = round($clockIn->diffInMinutes($clockOut) / 60, 2);

                    $timesheet = Timesheet::query()->firstOrNew([
                        'employeeId' => $employee->id,
                        'date' => $workDate,
                        'slotIndex' => $slotIndex,
                    ]);

                    $timesheet->departmentId = $employmentDetail->departmentId;
                    $timesheet->worksiteId = $employmentDetail->worksiteId;
                    $timesheet->payType = $payType;

                    $recalculated = $roundOffService->recalculate($timesheet, $roundOffIn, $roundOffOut);

                    $timesheet->fill([
                        'clockInTime' => $clockIn->format('Y-m-d H:i:s'),
                        'clockInDeviceId' => 'SEED-DEVICE',
                        'clockOutTime' => $clockOut->format('Y-m-d H:i:s'),
                        'clockOutDeviceId' => 'SEED-DEVICE',
                        'roundOffClockInTime' => $recalculated['roundOffClockInTime'],
                        'roundOffClockOutTime' => $recalculated['roundOffClockOutTime'],
                        'clockedHoursWorked' => $clockedHoursWorked,
                        'holidayHours' => $holidayHours,
                        'unpaidHours' => 0,
                        'workingStatus' => $holidayPayMultiplier !== null ? 'HOLIDAY' : $recalculated['workingStatus'],
                        'departmentId' => $employmentDetail->departmentId,
                        'worksiteId' => $employmentDetail->worksiteId,
                        'payType' => $payType,
                        'hourlyRate' => $payrollSnapshot['hourlyRate'],
                        'weeklySalary' => $payrollSnapshot['weeklySalary'],
                        'baseSalary' => $payrollSnapshot['baseSalary'],
                        'approvalStatus' => $approvalStatus,
                        'approvedBy' => $approvalStatus === 'APPROVED' ? $employee->id : null,
                        'approvedAt' => $approvalStatus === 'APPROVED' ? $date->copy()->setTime(18, 0)->format('Y-m-d H:i:s') : null,
                        'remarks' => null,
                    ]);

                    $timesheet->save();
                    $created++;
                }

                Timesheet::query()
                    ->where('employeeId', $employee->id)
                    ->whereDate('date', $workDate)
                    ->where('slotIndex', '>=', count($daySlots))
                    ->whereRaw('UPPER("approvalStatus") != ?', ['APPROVED'])
                    ->delete();

                $overtimeAllocator->redistributeEmployeeWeek((string) $employee->id, $date);
            }
        }

        $this->command?->info(sprintf(
            'TimesheetSeeder created/updated %d timesheet rows from %s to %s.',
            $created,
            $startDate->toDateString(),
            $endDate->toDateString(),
        ));
    }

    /**
     * @return array<string, float>
     */
    private function holidayMultipliers(Carbon $startDate, Carbon $endDate): array
    {
        $dates = [];

        $holidays = PublicHoliday::query()
            ->where('isActive', true)
            ->whereDate('startDate', '<=', $endDate->toDateString())
            ->whereDate('endDate', '>=', $startDate->toDateString())
            ->get();

        foreach ($holidays as $holiday) {
            $date = Carbon::parse($holiday->startDate)->max($startDate)->startOfDay();
            $holidayEnd = Carbon::parse($holiday->endDate)->min($endDate)->startOfDay();
            $multiplier = max(0, (float) ($holiday->payMultiplier ?? 1.5));

            while ($date->lte($holidayEnd)) {
                $key = $date->toDateString();
                $dates[$key] = isset($dates[$key]) ? max($dates[$key], $multiplier) : $multiplier;
                $date->addDay();
            }
        }

        return $dates;
    }

    /**
     * @param Collection<int, EmploymentDetail> $details
     */
    private function employmentDetailForDate(Collection $details, Carbon $date): ?EmploymentDetail
    {
        return $details->first(function (EmploymentDetail $detail) use ($date) {
            $startDate = Carbon::parse($detail->startDate)->startOfDay();
            $endDate = $detail->endDate ? Carbon::parse($detail->endDate)->startOfDay() : null;

            return $startDate->lte($date) && (!$endDate || $endDate->gte($date));
        });
    }

    /**
     * @param Collection<string, Collection<int, TimesheetTemplateDepartment>> $templateAssignments
     */
    private function timesheetTemplateForDate(
        Employee $employee,
        mixed $departmentId,
        Carbon $date,
        Collection $templateAssignments
    ): ?TimesheetTemplate {
        if ($employee->timesheetTemplateId) {
            if ($employee->relationLoaded('timesheetTemplate') && $employee->timesheetTemplate) {
                return $employee->timesheetTemplate;
            }

            return TimesheetTemplate::query()->find($employee->timesheetTemplateId);
        }

        if ($departmentId === null) {
            return null;
        }

        $assignments = $templateAssignments->get((string) $departmentId, collect());
        $assignment = $assignments->first(
            fn (TimesheetTemplateDepartment $item) => Carbon::parse($item->effective_date)->startOfDay()->lte($date)
        );

        return $assignment?->timesheetTemplate;
    }

    private function isScheduledWorkDay(?TimesheetTemplate $timesheetTemplate, Carbon $date, ?int $departmentId = null): bool
    {
        if (!$timesheetTemplate) {
            return $date->isWeekday();
        }

        if (!$timesheetTemplate->is_active) {
            return false;
        }

        return $timesheetTemplate->daySlotsFor($date->format('D'), $departmentId) !== [];
    }

    private function scheduledHoursForDate(?TimesheetTemplate $timesheetTemplate, Carbon $date, ?int $departmentId = null): float
    {
        if (!$timesheetTemplate) {
            return $date->isWeekday() ? (float) config('attendance.standard_daily_hours', 8) : 0.0;
        }

        $slots = $timesheetTemplate->daySlotsFor($date->format('D'), $departmentId);
        if ($slots === []) {
            return 0.0;
        }

        $totalMinutes = 0;

        foreach ($slots as $schedule) {
            $start = Carbon::parse($date->toDateString().' '.$schedule['start_time']);
            $end = Carbon::parse($date->toDateString().' '.$schedule['end_time']);

            if ($end->lte($start)) {
                $end->addDay();
            }

            $breakMinutes = LunchBreakHelper::breakMinutesFromSchedule([
                'include_lunch_hour' => $schedule['include_lunch_hour'] ?? false,
                'lunch_hour_hours' => $schedule['lunch_hour_hours'] ?? 1,
            ]);

            $totalMinutes += max(0, $start->diffInMinutes($end, false) - $breakMinutes);
        }

        return round($totalMinutes / 60, 2);
    }
}
