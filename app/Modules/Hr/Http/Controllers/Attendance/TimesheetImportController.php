<?php

namespace App\Modules\Hr\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Services\Attendance\TimesheetImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimesheetImportController extends Controller
{
    public function __construct(
        private readonly TimesheetImportService $importService,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'rows' => 'required|array|min:1|max:500',
            'rows.*.employeeCode' => 'nullable|string|max:64',
            'rows.*.employeeName' => 'nullable|string|max:255',
            'rows.*.departmentName' => 'nullable|string|max:255',
            'rows.*.date' => 'nullable',
            'rows.*.timeIn' => 'nullable',
            'rows.*.timeOut' => 'nullable',
            'rows.*.type' => 'nullable|string|max:64',
            'rows.*.lunchHours' => 'nullable|numeric',
        ]);

        $result = $this->importService->import($request->user(), array_values($validated['rows']));

        if ($result['errorCount'] > 0 || $result['created'] === 0) {
            return response()->json([
                'message' => $result['errorCount'] > 0
                    ? 'Resolve the timesheet rows before importing.'
                    : 'The file has no timesheet rows.',
                ...$result,
            ], 422);
        }

        return response()->json([
            'message' => sprintf(
                'Imported %d timesheet%s.',
                $result['created'],
                $result['created'] === 1 ? '' : 's',
            ),
            ...$result,
        ], 201);
    }
}
