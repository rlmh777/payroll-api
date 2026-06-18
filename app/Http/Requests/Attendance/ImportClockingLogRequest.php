<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class ImportClockingLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'hasHeader' => $this->boolean('hasHeader', true),
        ]);
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:10240',
                'mimes:csv,txt,xlsx',
            ],
            'hasHeader' => ['nullable', 'boolean'],
            'defaultDeviceId' => ['nullable', 'string', 'max:255'],
        ];
    }
}

