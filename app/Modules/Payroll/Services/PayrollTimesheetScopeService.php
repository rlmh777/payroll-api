<?php

namespace App\Modules\Payroll\Services;

use App\Models\Timesheet;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class PayrollTimesheetScopeService
{
    public function apply(
        Builder $query,
        Carbon $startDate,
        Carbon $endDate,
        string $payPeriodGroupId,
        ?int $payrateFrequencyId = null,
    ): Builder {
        $query = $query
            ->whereDate('date', '>=', $startDate->toDateString())
            ->whereDate('date', '<=', $endDate->toDateString())
            ->whereNotNull('employeeCompensationId')
            ->whereHas('employeeCompensation', function (Builder $compensationQuery) {
                $compensationQuery
                    ->where('isActive', true)
                    ->whereColumn('employee_compensation.effectiveDate', '<=', 'timesheet.date')
                    ->where(function (Builder $inner) {
                        $inner->whereNull('employee_compensation.endDate')
                            ->orWhereColumn('employee_compensation.endDate', '>=', 'timesheet.date');
                    });
            })
            ->whereHas('employmentDetail', function (Builder $employmentQuery) use ($payPeriodGroupId, $startDate, $endDate) {
                $employmentQuery
                    ->where('defaultPayPeriodGroupId', $payPeriodGroupId)
                    ->where('isActive', true)
                    ->whereDate('startDate', '<=', $endDate->toDateString())
                    ->where(function (Builder $inner) use ($startDate) {
                        $inner->whereNull('endDate')
                            ->orWhereDate('endDate', '>=', $startDate->toDateString());
                    });
            });

        if ($payrateFrequencyId !== null) {
            $query->whereHas('employee', function (Builder $employeeQuery) use ($payrateFrequencyId) {
                $employeeQuery->where('payrateFrequencyId', $payrateFrequencyId);
            });
        }

        return $query;
    }

    public function baseQuery(
        Carbon $startDate,
        Carbon $endDate,
        string $payPeriodGroupId,
        ?int $payrateFrequencyId = null,
    ): Builder {
        return $this->apply(
            Timesheet::query(),
            $startDate,
            $endDate,
            $payPeriodGroupId,
            $payrateFrequencyId,
        );
    }

    /**
     * @return list<string>
     */
    public function employeeIds(
        Carbon $startDate,
        Carbon $endDate,
        string $payPeriodGroupId,
        ?int $payrateFrequencyId = null,
    ): array {
        return $this->baseQuery($startDate, $endDate, $payPeriodGroupId, $payrateFrequencyId)
            ->distinct()
            ->pluck('employeeId')
            ->map(fn ($id) => (string) $id)
            ->filter()
            ->values()
            ->all();
    }
}
