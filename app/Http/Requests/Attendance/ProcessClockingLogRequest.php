<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class ProcessClockingLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'queue' => $this->boolean('queue', true),
        ]);
    }

    public function rules(): array
    {
        return [
            'payPeriodScheduleId' => ['nullable', 'uuid', 'exists:pay_period_schedule,id'],
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date', 'after_or_equal:startDate'],
            'biometricUserId' => ['nullable', 'string', 'max:255'],
            'overtimeThresholdHours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'queue' => ['nullable', 'boolean'],
        ];
    }
}
