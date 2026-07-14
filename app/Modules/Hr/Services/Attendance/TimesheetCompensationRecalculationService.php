<?php

namespace App\Modules\Hr\Services\Attendance;

use App\Enums\CompensationMethod;
use App\Models\EmployeeCompensation;
use App\Models\PayrollRun;
use App\Models\Timesheet;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TimesheetCompensationRecalculationService
{
    public function __construct(
        private readonly TimesheetRoundOffService $roundOffService,
        private readonly TimesheetCompensationPayService $compensationPayService,
    ) {
    }

    /**
     * @param array<int, string> $employeeIds
     * @return array{recalculatedTimesheets:int, recalculatedWeeks:int, startDate:string, endDate:?string}
     */
    public function recalculate(
        array $employeeIds = [],
        ?string $startDate = null,
        ?string $endDate = null,
        bool $allActiveCompensation = false,
        bool $includeProcessedPayrollRuns = false,
    ): array {
        $rangeStart = $startDate ? Carbon::parse($startDate)->startOfDay() : null;
        $rangeEnd = $endDate ? Carbon::parse($endDate)->startOfDay() : null;
        $employeeIds = array_values(array_unique(array_filter($employeeIds)));
        $unprocessedPeriods = collect();

        if (!$includeProcessedPayrollRuns) {
            $unprocessedPeriods = $this->unprocessedPayrollPeriods($rangeStart, $rangeEnd);

            if ($unprocessedPeriods->isEmpty()) {
                return [
                    'recalculatedTimesheets' => 0,
                    'recalculatedWeeks' => 0,
                    'startDate' => $rangeStart?->toDateString() ?? '',
                    'endDate' => $rangeEnd?->toDateString(),
                ];
            }

            $rangeStart = $rangeStart
                ? $rangeStart->max(Carbon::parse($unprocessedPeriods->min('startDate'))->startOfDay())
                : Carbon::parse($unprocessedPeriods->min('startDate'))->startOfDay();
            $rangeEnd = $rangeEnd
                ? $rangeEnd->min(Carbon::parse($unprocessedPeriods->max('endDate'))->startOfDay())
                : Carbon::parse($unprocessedPeriods->max('endDate'))->startOfDay();
        }

        $rangeStart ??= now()->startOfDay();

        if (!$allActiveCompensation && $employeeIds === []) {
            return [
                'recalculatedTimesheets' => 0,
                'recalculatedWeeks' => 0,
                'startDate' => $rangeStart->toDateString(),
                'endDate' => $rangeEnd?->toDateString(),
            ];
        }

        if ($allActiveCompensation || $employeeIds !== []) {
            $activeEmployeeIds = $this->activeCompensationEmployeeIds($employeeIds);

            if ($activeEmployeeIds->isEmpty()) {
                return [
                    'recalculatedTimesheets' => 0,
                    'recalculatedWeeks' => 0,
                    'startDate' => $rangeStart->toDateString(),
                    'endDate' => $rangeEnd?->toDateString(),
                ];
            }

            $employeeIds = $activeEmployeeIds->all();
        }

        $query = Timesheet::query()
            ->whereDate('date', '>=', $rangeStart->toDateString())
            ->orderBy('employeeId')
            ->orderBy('date')
            ->orderBy('slotIndex');

        if ($rangeEnd) {
            $query->whereDate('date', '<=', $rangeEnd->toDateString());
        }

        if (!$includeProcessedPayrollRuns) {
            $this->constrainToPayrollPeriods($query, $unprocessedPeriods);
        }

        if ($employeeIds !== []) {
            $query->whereIn('employeeId', $employeeIds);
        }

        $recalculated = 0;
        $weekKeys = [];
        $timesheetIdsByWeek = [];

        $query->get()->each(function (Timesheet $timesheet) use (&$recalculated, &$weekKeys, &$timesheetIdsByWeek) {
            $this->recalculateTimesheet($timesheet, false);

            if ($timesheet->employeeId && $timesheet->date) {
                $workDate = Carbon::parse($timesheet->date);
                $weekStart = $workDate->copy()->startOfWeek(Carbon::MONDAY);
                $weekKey = (string) $timesheet->employeeId.'|'.$weekStart->toDateString();
                $weekKeys[$weekKey] = [
                    (string) $timesheet->employeeId,
                    $workDate,
                ];
                $timesheetIdsByWeek[$weekKey][] = (string) $timesheet->id;
            }

            $recalculated++;
        });

        foreach ($weekKeys as $weekKey => [$employeeId, $workDate]) {
            $weekTimesheetIds = array_values(array_unique($timesheetIdsByWeek[$weekKey] ?? []));
            if ($weekTimesheetIds === []) {
                continue;
            }

            $weekTimesheets = Timesheet::query()
                ->whereIn('id', $weekTimesheetIds)
                ->orderBy('date')
                ->orderBy('slotIndex')
                ->get();

            $this->compensationPayService->syncPayContextForCollection($weekTimesheets);
            $this->compensationPayService->redistributeCollection($weekTimesheets);
        }

        return [
            'recalculatedTimesheets' => $recalculated,
            'recalculatedWeeks' => count($weekKeys),
            'startDate' => $rangeStart->toDateString(),
            'endDate' => $rangeEnd?->toDateString(),
        ];
    }

    /**
     * @param array<int, string> $employeeIds
     */
    private function activeCompensationEmployeeIds(array $employeeIds = []): Collection
    {
        return EmployeeCompensation::query()
            ->where('isActive', true)
            ->when($employeeIds !== [], fn ($query) => $query->whereIn('employeeId', $employeeIds))
            ->pluck('employeeId')
            ->filter()
            ->unique()
            ->values();
    }

    private function recalculateTimesheet(Timesheet $timesheet, bool $finalizeWeek = true): void
    {
        $roundOffIn = $timesheet->roundOffClockInTime ?: $timesheet->clockInTime;
        $roundOffOut = $timesheet->roundOffClockOutTime ?: $timesheet->clockOutTime;

        if ($roundOffIn && $roundOffOut) {
            $this->roundOffService->recalculate(
                $timesheet,
                Carbon::parse($roundOffIn),
                Carbon::parse($roundOffOut),
                $finalizeWeek,
            );

            return;
        }

        $payHours = $this->compensationPayService->applyToTimesheet($timesheet, 0.0);
        $method = CompensationMethod::fromStored($timesheet->payType);

        $timesheet->hoursWorked = $payHours['hoursWorked'];
        $timesheet->regularHours = $payHours['regularHours'];
        $timesheet->overtimeHours = $payHours['overtimeHours'];
        $timesheet->holidayHours = $payHours['holidayHours'];
        $timesheet->unpaidHours = $payHours['unpaidHours'];
        $timesheet->isPaid = (bool) ($payHours['isPaid'] ?? true);
        $timesheet->paidHours = round((float) ($payHours['paidHours'] ?? 0), 2);
        $timesheet->workingStatus = $payHours['workingStatus'];

        if ($method->allowsOvertime()) {
            $timesheet->regularHours = 0;
            $timesheet->overtimeHours = 0;
        }

        $timesheet->save();
    }

    /**
     * @return Collection<int, array{startDate:string,endDate:string,payPeriodGroupId:?string}>
     */
    private function unprocessedPayrollPeriods(?Carbon $rangeStart, ?Carbon $rangeEnd): Collection
    {
        return PayrollRun::query()
            ->with('payPeriodSchedule')
            ->where('status', 'draft')
            ->whereHas('payPeriodSchedule', function ($query) use ($rangeStart, $rangeEnd) {
                if ($rangeStart) {
                    $query->whereDate('end_date', '>=', $rangeStart->toDateString());
                }

                if ($rangeEnd) {
                    $query->whereDate('start_date', '<=', $rangeEnd->toDateString());
                }
            })
            ->get()
            ->map(fn (PayrollRun $run) => [
                'startDate' => $run->payPeriodSchedule?->start_date?->toDateString(),
                'endDate' => $run->payPeriodSchedule?->end_date?->toDateString(),
                'payPeriodGroupId' => $run->payPeriodSchedule?->pay_period_group_id
                    ? (string) $run->payPeriodSchedule->pay_period_group_id
                    : null,
            ])
            ->filter(fn (array $period) => $period['startDate'] && $period['endDate'])
            ->values();
    }

    /**
     * @param Collection<int, array{startDate:string,endDate:string,payPeriodGroupId:?string}> $periods
     */
    private function constrainToPayrollPeriods($query, Collection $periods): void
    {
        $query->where(function ($outer) use ($periods) {
            foreach ($periods as $period) {
                $outer->orWhere(function ($periodQuery) use ($period) {
                    $periodQuery
                        ->whereDate('date', '>=', $period['startDate'])
                        ->whereDate('date', '<=', $period['endDate']);

                    if ($period['payPeriodGroupId']) {
                        $periodQuery->where(function ($groupQuery) use ($period) {
                            $groupQuery
                                ->whereNull('employmentDetailId')
                                ->orWhereHas('employmentDetail', function ($detailQuery) use ($period) {
                                    $detailQuery->where(
                                        'defaultPayPeriodGroupId',
                                        $period['payPeriodGroupId'],
                                    );
                                });
                        });
                    }
                });
            }
        });
    }
}
