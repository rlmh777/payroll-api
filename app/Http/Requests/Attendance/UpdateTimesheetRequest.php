<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTimesheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            'roundOffClockInTime' => ['required', 'date'],
            'roundOffClockOutTime' => ['required', 'date', 'after:roundOffClockInTime'],
            'clockInTime' => ['nullable', 'date'],
            'clockOutTime' => ['nullable', 'date'],
            'clockInDeviceId' => ['nullable', 'string', 'max:100'],
            'clockOutDeviceId' => ['nullable', 'string', 'max:100'],
            'lunchHourHours' => ['nullable', 'numeric', 'min:0', 'max:8'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'departmentId' => ['nullable', 'integer', 'exists:department,id'],
            'worksiteId' => ['nullable', 'integer', 'exists:worksite,id'],
            'employmentDetailId' => ['nullable', 'uuid', 'exists:employment_detail,id'],
            'isPaid' => ['nullable', 'boolean'],
        ];
    }
}
