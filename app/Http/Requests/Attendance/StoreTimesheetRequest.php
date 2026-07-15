<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class StoreTimesheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employeeId' => ['required', 'uuid', 'exists:employee,id'],
            'date' => ['required', 'date'],
            'roundOffClockInTime' => ['required', 'date'],
            'roundOffClockOutTime' => ['required', 'date', 'after:roundOffClockInTime'],
            'slotIndex' => ['nullable', 'integer', 'min:0'],
            'lunchHourHours' => ['nullable', 'numeric', 'min:0', 'max:8'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'employmentDetailId' => ['nullable', 'uuid', 'exists:employment_detail,id'],
            'departmentId' => ['nullable', 'integer', 'exists:department,id'],
        ];
    }
}
