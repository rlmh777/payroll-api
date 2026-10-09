<?php

namespace App\Modules\Hr\Services;

use App\Enums\BirthdayVisibility;
use App\Enums\LeaveStatusCode;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\EmploymentDetail;
use App\Models\HrSetting;
use App\Models\Payroll;
use App\Models\PublicHoliday;
use App\Models\ScheduledWork;
use App\Models\SchedulerNotice;
use App\Models\User;
use App\Modules\Hr\Services\Leave\LeaveEntitlementService;
use App\Support\PersonName;
use Carbon\Carbon;

class EmployeeDashboardService
{
    public function __construct(
        private readonly LeaveEntitlementService $leaveEntitlementService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, ?string $weekStart = null): array
    {
        $employee = Employee::query()
            ->with(['person', 'employmentDetails.department'])
            ->where('user_id', $user->id)
            ->first();

        $week = $this->resolveWeek($weekStart);
        $visibility = HrSetting::current()->birthdayVisibility();

        if (! $employee) {
            return [
                'employee' => null,
                'birthdayVisibility' => $visibility->value,
                'schedule' => [
                    'start' => $week['start']->toDateString(),
                    'end' => $week['end']->toDateString(),
                    'days' => $this->emptyScheduleDays($week['start']),
                ],
                'upcomingEvents' => [],
                'leaves' => [
                    'asOf' => Carbon::today()->toDateString(),
                    'balances' => [],
                    'upcoming' => [],
                ],
                'payslips' => [],
            ];
        }

        $departmentId = $this->activeDepartmentId($employee);

        return [
            'employee' => [
                'id' => $employee->id,
                'code' => $employee->code,
                'name' => PersonName::lastFirst($employee->firstName, $employee->lastName) ?? $employee->code,
                'departmentId' => $departmentId,
                'departmentName' => $this->activeDepartmentName($employee),
            ],
            'birthdayVisibility' => $visibility->value,
            'schedule' => $this->buildSchedule($employee, $week['start'], $week['end']),
            'upcomingEvents' => $this->buildUpcomingEvents($employee, $departmentId, $visibility),
            'leaves' => $this->buildLeaves($employee),
            'payslips' => $this->buildPayslips($employee),
        ];
    }

    /**
     * @return array{start: Carbon, end: Carbon}
     */
    private function resolveWeek(?string $weekStart): array
    {
        $start = $weekStart
            ? Carbon::parse($weekStart)->startOfWeek(Carbon::MONDAY)->startOfDay()
            : Carbon::today()->startOfWeek(Carbon::MONDAY)->startOfDay();

        return [
            'start' => $start,
            'end' => $start->copy()->endOfWeek(Carbon::SUNDAY)->startOfDay(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function emptyScheduleDays(Carbon $weekStart): array
    {
        $days = [];
        for ($offset = 0; $offset < 7; $offset++) {
            $date = $weekStart->copy()->addDays($offset);
            $days[] = [
                'date' => $date->toDateString(),
                'weekday' => $date->format('D'),
                'shifts' => [],
                'holiday' => null,
                'leave' => null,
            ];
        }

        return $days;
    }

    private function activeDepartmentId(Employee $employee): ?int
    {
        $detail = $this->activeEmploymentDetail($employee);

        return $detail?->departmentId ? (int) $detail->departmentId : null;
    }

    private function activeDepartmentName(Employee $employee): ?string
    {
        return $this->activeEmploymentDetail($employee)?->department?->name;
    }

    private function activeEmploymentDetail(Employee $employee): ?EmploymentDetail
    {
        return $employee->employmentDetails
            ->first(fn (EmploymentDetail $detail) => (bool) $detail->isActive)
            ?? $employee->employmentDetails->sortByDesc('startDate')->first();
    }

    /**
     * @return array{start: string, end: string, days: list<array<string, mixed>>}
     */
    private function buildSchedule(Employee $employee, Carbon $start, Carbon $end): array
    {
        $days = collect($this->emptyScheduleDays($start))->keyBy('date');

        $shifts = ScheduledWork::query()
            ->with(['department:id,name', 'worksite:id,name'])
            ->where('employeeId', $employee->id)
            ->whereDate('startDate', '<=', $end->toDateString())
            ->whereDate('endDate', '>=', $start->toDateString())
            ->orderBy('startTime')
            ->get();

        foreach ($shifts as $shift) {
            $rangeStart = Carbon::parse($shift->startDate)->max($start);
            $rangeEnd = Carbon::parse($shift->endDate)->min($end);
            for ($date = $rangeStart->copy(); $date->lte($rangeEnd); $date->addDay()) {
                $key = $date->toDateString();
                $day = $days->get($key);
                if (! $day) {
                    continue;
                }
                $day['shifts'][] = [
                    'id' => $shift->id,
                    'startTime' => $shift->startTime ? Carbon::parse($shift->startTime)->format('H:i') : null,
                    'endTime' => $shift->endTime ? Carbon::parse($shift->endTime)->format('H:i') : null,
                    'description' => $shift->description,
                    'departmentName' => $shift->department?->name,
                    'worksiteName' => $shift->worksite?->name,
                ];
                $days->put($key, $day);
            }
        }

        $holidays = PublicHoliday::query()
            ->where('isActive', true)
            ->whereDate('startDate', '<=', $end->toDateString())
            ->whereDate('endDate', '>=', $start->toDateString())
            ->get();

        foreach ($holidays as $holiday) {
            $rangeStart = Carbon::parse($holiday->startDate)->max($start);
            $rangeEnd = Carbon::parse($holiday->endDate)->min($end);
            for ($date = $rangeStart->copy(); $date->lte($rangeEnd); $date->addDay()) {
                $key = $date->toDateString();
                $day = $days->get($key);
                if (! $day) {
                    continue;
                }
                $day['holiday'] = [
                    'id' => $holiday->id,
                    'name' => $holiday->name,
                ];
                $days->put($key, $day);
            }
        }

        $leaves = EmployeeLeave::query()
            ->with(['leaveType', 'leaveStatus'])
            ->where('employeeId', $employee->id)
            ->whereDate('startDate', '<=', $end->toDateString())
            ->whereDate('endDate', '>=', $start->toDateString())
            ->whereHas('leaveStatus', fn ($query) => $query->whereNotIn('code', [
                LeaveStatusCode::Cancelled->value,
                LeaveStatusCode::Rejected->value,
            ]))
            ->get();

        foreach ($leaves as $leave) {
            $rangeStart = Carbon::parse($leave->startDate)->max($start);
            $rangeEnd = Carbon::parse($leave->endDate)->min($end);
            for ($date = $rangeStart->copy(); $date->lte($rangeEnd); $date->addDay()) {
                $key = $date->toDateString();
                $day = $days->get($key);
                if (! $day) {
                    continue;
                }
                $day['leave'] = [
                    'id' => $leave->id,
                    'leaveTypeName' => $leave->leaveType?->name ?? 'Leave',
                    'status' => $leave->leaveStatus?->code ?? $leave->statusCode,
                ];
                $days->put($key, $day);
            }
        }

        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'days' => $days->values()->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildUpcomingEvents(
        Employee $employee,
        ?int $departmentId,
        BirthdayVisibility $visibility,
    ): array {
        $from = Carbon::today()->startOfDay();
        $to = $from->copy()->addDays(60);
        $events = collect();

        $holidays = PublicHoliday::query()
            ->where('isActive', true)
            ->whereDate('startDate', '<=', $to->toDateString())
            ->whereDate('endDate', '>=', $from->toDateString())
            ->orderBy('startDate')
            ->get();

        foreach ($holidays as $holiday) {
            $events->push([
                'id' => 'holiday-'.$holiday->id,
                'type' => 'holiday',
                'title' => $holiday->name,
                'startDate' => Carbon::parse($holiday->startDate)->toDateString(),
                'endDate' => Carbon::parse($holiday->endDate)->toDateString(),
            ]);
        }

        $notices = SchedulerNotice::query()
            ->with(['departments:id', 'employees:id'])
            ->where('is_active', true)
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
            ->orderBy('start_date')
            ->get()
            ->filter(fn (SchedulerNotice $notice) => $this->noticeVisibleToEmployee($notice, $employee, $departmentId));

        foreach ($notices as $notice) {
            $events->push([
                'id' => 'event-'.$notice->id,
                'type' => 'event',
                'title' => $notice->title,
                'description' => $notice->description,
                'startDate' => $notice->start_date?->toDateString(),
                'endDate' => $notice->end_date?->toDateString(),
                'color' => $notice->color,
            ]);
        }

        foreach ($this->birthdayEvents($from, $to, $employee, $departmentId, $visibility) as $birthday) {
            $events->push($birthday);
        }

        return $events
            ->sortBy(fn (array $event) => ($event['startDate'] ?? '').$event['type'].($event['title'] ?? ''))
            ->values()
            ->all();
    }

    private function noticeVisibleToEmployee(
        SchedulerNotice $notice,
        Employee $employee,
        ?int $departmentId,
    ): bool {
        return match ($notice->audience_type) {
            SchedulerNotice::AUDIENCE_COMPANY => true,
            SchedulerNotice::AUDIENCE_DEPARTMENTS => $departmentId !== null
                && $notice->departments->contains(fn ($department) => (int) $department->id === $departmentId),
            SchedulerNotice::AUDIENCE_EMPLOYEES => $notice->employees->contains(
                fn ($assigned) => (string) $assigned->id === (string) $employee->id,
            ),
            default => false,
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function birthdayEvents(
        Carbon $from,
        Carbon $to,
        Employee $viewer,
        ?int $departmentId,
        BirthdayVisibility $visibility,
    ): array {
        if ($visibility === BirthdayVisibility::None) {
            return [];
        }

        $employees = Employee::query()
            ->with(['person:id,firstName,lastName,birthdate', 'employmentDetails'])
            ->select(['id', 'person_id'])
            ->get();

        if ($visibility === BirthdayVisibility::Department) {
            if ($departmentId === null) {
                $employees = $employees->where('id', $viewer->id);
            } else {
                $employees = $employees->filter(function (Employee $employee) use ($departmentId) {
                    return $employee->employmentDetails->contains(
                        fn (EmploymentDetail $detail) => $detail->isActive && (int) $detail->departmentId === $departmentId,
                    );
                });
            }
        }

        $events = [];
        $yearStart = (int) $from->format('Y');
        $yearEnd = (int) $to->format('Y');

        foreach ($employees as $employee) {
            if (! $employee->birthdate) {
                continue;
            }

            $birth = Carbon::parse($employee->birthdate);
            for ($year = $yearStart; $year <= $yearEnd; $year++) {
                if ($birth->month === 2 && $birth->day === 29 && ! Carbon::create($year)->isLeapYear()) {
                    $birthday = Carbon::create($year, 2, 28);
                } else {
                    $birthday = Carbon::create($year, $birth->month, $birth->day);
                }

                if ($birthday && $birthday->betweenIncluded($from, $to)) {
                    $name = PersonName::lastFirst($employee->firstName, $employee->lastName) ?? 'Employee';
                    $events[] = [
                        'id' => sprintf('birthday-%s-%s', $employee->id, $birthday->toDateString()),
                        'type' => 'birthday',
                        'title' => $name.'\'s birthday',
                        'startDate' => $birthday->toDateString(),
                        'endDate' => $birthday->toDateString(),
                        'employeeId' => $employee->id,
                    ];
                }
            }
        }

        return $events;
    }

    /**
     * @return array{asOf: string, balances: list<array<string, mixed>>, upcoming: list<array<string, mixed>>}
     */
    private function buildLeaves(Employee $employee): array
    {
        $asOf = Carbon::today()->startOfDay();

        $upcoming = EmployeeLeave::query()
            ->with(['leaveType', 'leaveStatus'])
            ->where('employeeId', $employee->id)
            ->whereDate('endDate', '>=', $asOf->toDateString())
            ->whereHas('leaveStatus', fn ($query) => $query->whereNotIn('code', [
                LeaveStatusCode::Cancelled->value,
                LeaveStatusCode::Rejected->value,
            ]))
            ->orderBy('startDate')
            ->limit(8)
            ->get()
            ->map(fn (EmployeeLeave $leave) => [
                'id' => $leave->id,
                'leaveTypeName' => $leave->leaveType?->name ?? 'Leave',
                'status' => $leave->leaveStatus?->name ?? $leave->leaveStatus?->code,
                'startDate' => Carbon::parse($leave->startDate)->toDateString(),
                'endDate' => Carbon::parse($leave->endDate)->toDateString(),
                'totalDays' => (float) ($leave->totalDays ?? 0),
            ])
            ->all();

        return [
            'asOf' => $asOf->toDateString(),
            'balances' => $this->leaveEntitlementService->balancesForEmployee($employee->id, $asOf),
            'upcoming' => $upcoming,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildPayslips(Employee $employee): array
    {
        return Payroll::query()
            ->with(['payrollRun.payPeriodSchedule'])
            ->where('employeeId', $employee->id)
            ->whereHas('payrollRun', fn ($query) => $query->whereRaw('LOWER(status) = ?', ['posted']))
            ->orderByDesc('date')
            ->limit(12)
            ->get()
            ->map(function (Payroll $payroll) {
                $schedule = $payroll->payrollRun?->payPeriodSchedule;

                return [
                    'id' => $payroll->id,
                    'payrollRunId' => $payroll->payroll_run_id,
                    'payrollNumber' => $payroll->payrollRun?->payrollNumberFormatted,
                    'date' => optional($payroll->date)?->toDateString(),
                    'periodStart' => optional($schedule?->start_date)?->toDateString(),
                    'periodEnd' => optional($schedule?->end_date)?->toDateString(),
                    'grossSalary' => (float) $payroll->grossSalary,
                    'netSalary' => (float) $payroll->netSalary,
                    'totalDeductions' => (float) $payroll->totalDeductions,
                    'totalAllowances' => (float) $payroll->totalAllowances,
                ];
            })
            ->all();
    }
}
