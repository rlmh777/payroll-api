<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTimesheetRoundOffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'roundOffClockInTime' => ['nullable', 'date'],
            'roundOffClockOutTime' => ['nullable', 'date', 'after:roundOffClockInTime'],
        ];
    }
}
