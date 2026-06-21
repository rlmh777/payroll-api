<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class BulkApproveTimesheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('approvalStatus')) {
            $this->merge([
                'approvalStatus' => strtoupper((string) $this->input('approvalStatus')),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'timesheetIds' => ['required', 'array', 'min:1', 'max:500'],
            'timesheetIds.*' => ['required', 'uuid', 'distinct', 'exists:timesheet,id'],
            'approvalStatus' => ['required', 'string', 'in:APPROVED'],
            'remarks' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
