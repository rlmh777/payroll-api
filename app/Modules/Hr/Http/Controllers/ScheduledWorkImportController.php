<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Modules\Hr\Services\Attendance\ScheduledWorkImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduledWorkImportController extends Controller
{
    public function __construct(
        private readonly ScheduledWorkImportService $importService,
    ) {
    }

    public function preview(Request $request): JsonResponse
    {
        $rows = $this->validatedRows($request);
        $preview = $this->importService->preview($request->user(), $rows);

        return response()->json($preview);
    }

    public function confirm(Request $request): JsonResponse
    {
        $rows = $this->validatedRows($request);
        $result = $this->importService->confirm($request->user(), $rows);

        if (($result['errorCount'] ?? 0) > 0 || ($result['created'] ?? 0) === 0) {
            return response()->json([
                'message' => ($result['errorCount'] ?? 0) > 0
                    ? 'Resolve import errors before applying shifts.'
                    : 'No valid shifts to import.',
                'recordCount' => $result['recordCount'],
                'errorCount' => $result['errorCount'],
                'validCount' => $result['validCount'],
                'companyWide' => $result['companyWide'],
                'created' => $result['created'] ?? 0,
                'rows' => $result['rows'],
            ], 422);
        }

        return response()->json([
            'message' => sprintf(
                'Imported %d scheduled shift%s.',
                $result['created'],
                $result['created'] === 1 ? '' : 's',
            ),
            'recordCount' => $result['recordCount'],
            'errorCount' => $result['errorCount'],
            'validCount' => $result['validCount'],
            'companyWide' => $result['companyWide'],
            'created' => $result['created'],
            'rows' => $result['rows'],
            'data' => $result['createdRecords'],
        ], 201);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validatedRows(Request $request): array
    {
        $validated = $request->validate([
            'rows' => 'required|array|min:1|max:500',
            'rows.*.employeeCode' => 'nullable|string|max:64',
            'rows.*.employeeIdentifier' => 'nullable|string|max:64',
            'rows.*.departmentName' => 'nullable|string|max:255',
            'rows.*.worksiteName' => 'nullable|string|max:255',
            'rows.*.startDate' => 'nullable',
            'rows.*.endDate' => 'nullable',
            'rows.*.startTime' => 'nullable',
            'rows.*.endTime' => 'nullable',
            'rows.*.description' => 'nullable|string|max:1024',
            'rows.*.rate' => 'nullable|numeric|min:0|max:9.99',
            'rows.*.includeLunchHour' => 'nullable',
            'rows.*.lunchHourHours' => 'nullable|numeric|min:0|max:8',
        ]);

        return array_values($validated['rows']);
    }
}
