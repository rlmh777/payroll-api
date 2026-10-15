<?php

namespace App\Http\Requests\Attendance;

use App\Enums\TimesheetPunctualityStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTimesheetPunctualityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $statusRule = ['nullable', 'string', Rule::in([
            ...TimesheetPunctualityStatus::values(),
            'AUTO',
        ])];

        return [
            'clockInPunctuality' => $statusRule,
            'clockOutPunctuality' => $statusRule,
        ];
    }
}
