<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class ApproveTimesheetRequest extends FormRequest
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
            'approvalStatus' => ['required', 'string', 'in:APPROVED,REJECTED'],
            'remarks' => ['nullable', 'string', 'max:5000'],
        ];
    }
}

