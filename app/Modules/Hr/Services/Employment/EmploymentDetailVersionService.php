<?php

namespace App\Modules\Hr\Services\Employment;

use App\Models\EmploymentDetail;
use Carbon\Carbon;
use Illuminate\Support\Str;

class EmploymentDetailVersionService
{
    /**
     * @param array<string, mixed> $data
     */
    public function assignmentFieldsChanged(EmploymentDetail $existing, array $data): bool
    {
        if (array_key_exists('departmentId', $data)
            && (int) $data['departmentId'] !== (int) $existing->departmentId) {
            return true;
        }

        if (array_key_exists('worksiteId', $data)
            && (int) $data['worksiteId'] !== (int) $existing->worksiteId) {
            return true;
        }

        if (array_key_exists('defaultPayPeriodGroupId', $data)
            && (string) $data['defaultPayPeriodGroupId'] !== (string) $existing->defaultPayPeriodGroupId) {
            return true;
        }

        return false;
    }

    /**
     * Close the active record and create a successor employment detail row.
     *
     * @param array<string, mixed> $data
     */
    public function revise(EmploymentDetail $existing, array $data): EmploymentDetail
    {
        $effectiveDate = $this->resolveEffectiveDate($data, $existing);
        $closeDate = $effectiveDate->copy()->subDay();

        if ($existing->isActive) {
            $existingStart = Carbon::parse($existing->startDate)->startOfDay();
            $existing->endDate = $closeDate->lt($existingStart)
                ? $existingStart->toDateString()
                : $closeDate->toDateString();
            $existing->isActive = false;
            $existing->save();
        }

        $successorData = array_merge(
            $existing->only($existing->getFillable()),
            $data,
            [
                'id' => (string) Str::uuid(),
                'employeeId' => $existing->employeeId,
                'startDate' => $effectiveDate->toDateString(),
                'endDate' => $data['endDate'] ?? null,
                'isActive' => (bool) ($data['isActive'] ?? true),
            ]
        );

        return EmploymentDetail::create($successorData);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveEffectiveDate(array $data, EmploymentDetail $existing): Carbon
    {
        if (!empty($data['startDate'])) {
            $candidate = Carbon::parse($data['startDate'])->startOfDay();
            $existingStart = Carbon::parse($existing->startDate)->startOfDay();

            if (!$candidate->equalTo($existingStart)) {
                return $candidate;
            }
        }

        return Carbon::today()->startOfDay();
    }
}
