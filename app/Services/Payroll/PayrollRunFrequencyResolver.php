<?php

namespace App\Services\Payroll;

use App\Models\PayPeriodGroup;
use App\Models\PayPeriodSchedule;
use App\Models\PayrateFrequency;
use App\Models\PayrollRun;

class PayrollRunFrequencyResolver
{
    public function resolveForRun(PayrollRun $payrollRun, ?PayPeriodSchedule $schedule = null): ?int
    {
        if ($payrollRun->payrate_frequency_id) {
            return (int) $payrollRun->payrate_frequency_id;
        }

        $schedule ??= $payrollRun->payPeriodSchedule;
        $groupName = (string) ($schedule?->payPeriodGroup?->name ?? '');

        return $this->resolveFromGroupName($groupName);
    }

    public function resolveFromGroupName(string $groupName): ?int
    {
        $normalized = strtolower(trim($groupName));

        if ($normalized === '') {
            return null;
        }

        if (str_contains($normalized, 'biweek') || str_contains($normalized, 'bi-week')) {
            return $this->frequencyIdForName('Biweekly');
        }

        if (str_contains($normalized, 'month')) {
            return $this->frequencyIdForName('Monthly');
        }

        if (str_contains($normalized, 'week')) {
            return $this->frequencyIdForName('Weekly') ?? $this->frequencyIdForName('Biweekly');
        }

        return null;
    }

    public function resolveFromGroup(?PayPeriodGroup $group): ?int
    {
        if (!$group) {
            return null;
        }

        return $this->resolveFromGroupName((string) $group->name);
    }

    private function frequencyIdForName(string $name): ?int
    {
        $id = PayrateFrequency::query()->where('name', $name)->value('id');

        return $id ? (int) $id : null;
    }
}
