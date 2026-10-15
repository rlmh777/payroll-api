<?php

namespace App\Modules\Payroll\Services;

use App\Enums\PayPeriodCadence;
use App\Models\PayPeriodGroup;
use Carbon\Carbon;

class PayPeriodCadenceResolver
{
    public function fromGroup(?PayPeriodGroup $group): PayPeriodCadence
    {
        if (! $group) {
            return PayPeriodCadence::Unknown;
        }

        return $this->fromText((string) $group->name, $group->rules);
    }

    public function fromText(string $name, mixed $rules = null): PayPeriodCadence
    {
        $text = strtolower(trim($name.' '.(is_string($rules) ? $rules : '')));
        $text = str_replace(['-', '_', '/', '|', '='], ' ', $text);
        $text = (string) preg_replace('/\s+/', ' ', $text);

        if ($text === '') {
            return PayPeriodCadence::Unknown;
        }

        if ($this->isSemiMonthly($text)) {
            return PayPeriodCadence::SemiMonthly;
        }

        if (str_contains($text, 'biweek') || str_contains($text, 'bi week') || str_contains($text, 'every 2 week') || str_contains($text, 'every two week')) {
            return PayPeriodCadence::Biweekly;
        }

        if (preg_match('/\bweekly\b/', $text) || (str_contains($text, 'week') && ! str_contains($text, 'month'))) {
            return PayPeriodCadence::Weekly;
        }

        if (str_contains($text, 'month')) {
            return PayPeriodCadence::Monthly;
        }

        return PayPeriodCadence::Unknown;
    }

    public function epochDate(PayPeriodCadence $cadence, string $payDate, mixed $rules = null): string
    {
        $pay = Carbon::parse($payDate)->startOfDay();
        $anchor = $this->parseAnchorDate($rules);

        if ($anchor instanceof Carbon && $anchor->lte($pay)) {
            return $anchor->toDateString();
        }

        return $pay->copy()->startOfYear()->toDateString();
    }

    public function parseAnchorDate(mixed $rules): ?Carbon
    {
        if (! is_string($rules) || trim($rules) === '') {
            return null;
        }

        if (preg_match('/anchor[_\s-]*date[^\d]{0,12}(\d{4}-\d{2}-\d{2})/i', $rules, $matches)) {
            try {
                return Carbon::parse($matches[1])->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function isSemiMonthly(string $text): bool
    {
        if (str_contains($text, 'semi month') || str_contains($text, 'semimonth')) {
            return true;
        }

        if ((str_contains($text, 'twice') || str_contains($text, 'two payroll') || str_contains($text, '2 payroll')) && str_contains($text, 'month')) {
            return true;
        }

        $hasFifteenth = str_contains($text, '15th') || preg_match('/\b15\b/', $text);
        $hasMonthEnd = str_contains($text, '30th')
            || str_contains($text, '31st')
            || preg_match('/\b(30|31)\b/', $text)
            || str_contains($text, 'end of month')
            || str_contains($text, 'last day')
            || str_contains($text, 'last business day');

        return $hasFifteenth && $hasMonthEnd;
    }
}
