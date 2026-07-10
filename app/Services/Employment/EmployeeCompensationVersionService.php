<?php

namespace App\Services\Employment;

use App\Models\EmployeeCompensation;
use Carbon\Carbon;
use Illuminate\Support\Str;

class EmployeeCompensationVersionService
{
    /**
     * @param array<string, mixed> $data
     */
    public function compensationFieldsChanged(EmployeeCompensation $existing, array $data): bool
    {
        foreach (['compensationMethod', 'requiresClocking', 'hourlyRate', 'yearlyRate', 'standardWeeklyHours', 'payscale', 'payscalePoint'] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            if ($field === 'compensationMethod') {
                if (strtoupper((string) $data[$field]) !== strtoupper((string) $existing->compensationMethod)) {
                    return true;
                }
                continue;
            }

            if ($field === 'requiresClocking') {
                if ((bool) $data[$field] !== (bool) $existing->{$field}) {
                    return true;
                }
                continue;
            }

            if (in_array($field, ['payscale', 'payscalePoint'], true)) {
                $incoming = trim((string) ($data[$field] ?? '')) ?: null;
                $current = trim((string) ($existing->{$field} ?? '')) ?: null;
                if ($incoming !== $current) {
                    return true;
                }
                continue;
            }

            if (round((float) $data[$field], 2) !== round((float) $existing->{$field}, 2)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function revise(EmployeeCompensation $existing, array $data): EmployeeCompensation
    {
        $effectiveDate = $this->resolveEffectiveDate($data, $existing);
        $closeDate = $effectiveDate->copy()->subDay();

        if ($existing->isActive) {
            $existingStart = Carbon::parse($existing->effectiveDate)->startOfDay();
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
                'effectiveDate' => $effectiveDate->toDateString(),
                'endDate' => $data['endDate'] ?? null,
                'isActive' => (bool) ($data['isActive'] ?? true),
            ],
        );

        return EmployeeCompensation::create($successorData);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveEffectiveDate(array $data, EmployeeCompensation $existing): Carbon
    {
        if (!empty($data['effectiveDate'])) {
            $candidate = Carbon::parse($data['effectiveDate'])->startOfDay();
            $existingStart = Carbon::parse($existing->effectiveDate)->startOfDay();

            if (!$candidate->equalTo($existingStart)) {
                return $candidate;
            }
        }

        return Carbon::today()->startOfDay();
    }
}
