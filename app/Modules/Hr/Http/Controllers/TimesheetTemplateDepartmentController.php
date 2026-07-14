<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Models\TimesheetTemplateDepartment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TimesheetTemplateDepartmentController extends Controller
{
    /**
     * Display a listing of timesheet template assignments.
     */
    public function index(Request $request): JsonResponse
    {
        $query = TimesheetTemplateDepartment::query()->with(['timesheetTemplate', 'department']);

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->string('department_id'));
        }

        $assignments = $query->orderByDesc('effective_date')->get();

        return response()->json($assignments);
    }

    /**
     * Store a newly created assignment.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'timesheet_template_id' => ['required', 'uuid', 'exists:timesheet_template,id'],
            'department_id' => ['required', 'integer', 'exists:department,id'],
            'effective_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $assignment = TimesheetTemplateDepartment::create([
            'id' => (string) Str::uuid(),
            'timesheet_template_id' => $validated['timesheet_template_id'],
            'department_id' => $validated['department_id'],
            'effective_date' => $validated['effective_date'],
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json($assignment, 201);
    }
}
