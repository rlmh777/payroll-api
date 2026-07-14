<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Services\Employee\EmployeeImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EmployeeImportController extends Controller
{
    public function __construct(
        private readonly EmployeeImportService $importService,
    ) {
    }

    public function template(): BinaryFileResponse|JsonResponse
    {
        $path = base_path('docs/templates/employee-import-template.xlsx');

        if (!is_file($path)) {
            return response()->json([
                'message' => 'Employee import template file was not found.',
            ], 404);
        }

        return response()->download(
            $path,
            'employee-import-template.xlsx',
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
        );
    }

    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employees' => ['nullable', 'array'],
            'employees.*' => ['array'],
            'employment' => ['nullable', 'array'],
            'employment.*' => ['array'],
            'compensation' => ['nullable', 'array'],
            'compensation.*' => ['array'],
            'scheduledWork' => ['nullable', 'array'],
            'scheduledWork.*' => ['array'],
            'clockingLogs' => ['nullable', 'array'],
            'clockingLogs.*' => ['array'],
        ]);

        $result = $this->importService->import($validated);

        return response()->json($result);
    }
}
