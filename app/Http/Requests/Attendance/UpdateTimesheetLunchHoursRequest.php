<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTimesheetLunchHoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lunchHourHours' => ['required', 'numeric', 'min:0', 'max:8'],
        ];
    }
}
