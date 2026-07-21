<?php

namespace App\Modules\Hr\Services\Employment;

use App\Models\EmployeeCompensation;
use App\Models\EmploymentDetail;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class EmploymentContractAssignmentService
{
    public function resolveContractId(
        ?string $employeeId,
        ?string $employmentDetailId,
        ?int $departmentId,
        string $startDate,
        ?string $endDate = null,
    ): ?string {
        if (!$employeeId) {
            return null;
        }

        if ($employmentDetailId) {
            $contract = EmploymentDetail::query()->find($employmentDetailId);

            if (!$contract || (string) $contract->employeeId !== (string) $employeeId) {
                throw ValidationException::withMessages([
                    'employmentDetailId' => ['Select an employment contract that belongs to this employee.'],
                ]);
            }

            return (string) $contract->id;
        }

        $contracts = $this->activeContracts($employeeId, $startDate, $endDate);
        if ($departmentId) {
            $departmentContracts = $contracts
                ->filter(fn (EmploymentDetail $contract) => (int) $contract->departmentId === $departmentId)
                ->values();

            if ($departmentContracts->count() === 1) {
                return (string) $departmentContracts->first()->id;
            }
        }

        if ($contracts->count() === 1) {
            return (string) $contracts->first()->id;
        }

        if ($contracts->count() > 1) {
            throw ValidationException::withMessages([
                'employmentDetailId' => ['Select the employment contract for this employee.'],
            ]);
        }

        return null;
    }

    public function compensationForContractDate(?string $employmentDetailId, string $date): ?EmployeeCompensation
    {
        if (!$employmentDetailId) {
            return null;
        }

        return EmployeeCompensation::query()
            ->where('employmentDetailId', $employmentDetailId)
            ->whereDate('effectiveDate', '<=', $date)
            ->where(function ($query) use ($date) {
                $query->whereNull('endDate')
                    ->orWhereDate('endDate', '>=', $date);
            })
            ->orderByDesc('isActive')
            ->orderByDesc('effectiveDate')
            ->first();
    }

    /**
     * Prefetch compensations for many contracts over a date range (avoids N+1 on scheduler).
     *
     * @param  array<int, string>  $employmentDetailIds
     * @return Collection<string, Collection<int, EmployeeCompensation>>
     */
    public function compensationsForContracts(array $employmentDetailIds, string $startDate, string $endDate): Collection
    {
        $ids = array_values(array_unique(array_filter($employmentDetailIds)));
        if ($ids === []) {
            return collect();
        }

        $start = Carbon::parse($startDate)->toDateString();
        $end = Carbon::parse($endDate)->toDateString();

        return EmployeeCompensation::query()
            ->whereIn('employmentDetailId', $ids)
            ->whereDate('effectiveDate', '<=', $end)
            ->where(function ($query) use ($start) {
                $query->whereNull('endDate')
                    ->orWhereDate('endDate', '>=', $start);
            })
            ->orderByDesc('isActive')
            ->orderByDesc('effectiveDate')
            ->get()
            ->groupBy(fn (EmployeeCompensation $row) => (string) $row->employmentDetailId);
    }

    public function pickCompensationForDate(Collection $compensations, string $date): ?EmployeeCompensation
    {
        $day = Carbon::parse($date)->toDateString();

        return $compensations
            ->first(function (EmployeeCompensation $compensation) use ($day) {
                $effective = optional($compensation->effectiveDate)->toDateString()
                    ?? (string) $compensation->effectiveDate;
                $end = $compensation->endDate
                    ? (optional($compensation->endDate)->toDateString() ?? (string) $compensation->endDate)
                    : null;

                return $effective <= $day && ($end === null || $end >= $day);
            });
    }

    /**
     * @return Collection<int, EmploymentDetail>
     */
    private function activeContracts(string $employeeId, string $startDate, ?string $endDate = null): Collection
    {
        $start = Carbon::parse($startDate)->toDateString();
        $end = Carbon::parse($endDate ?: $startDate)->toDateString();

        return EmploymentDetail::query()
            ->where('employeeId', $employeeId)
            ->whereDate('startDate', '<=', $end)
            ->where(function ($query) use ($start) {
                $query->whereNull('endDate')
                    ->orWhereDate('endDate', '>=', $start);
            })
            ->orderByDesc('isActive')
            ->orderByDesc('startDate')
            ->get();
    }
}
