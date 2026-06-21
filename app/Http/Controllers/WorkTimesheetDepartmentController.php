<?php

namespace App\Http\Controllers;

use App\Models\WorkTimesheetDepartment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WorkTimesheetDepartmentController extends Controller
{
    /**
     * Display a listing of work timesheet assignments.
     */
    public function index(Request $request): JsonResponse
    {
        $query = WorkTimesheetDepartment::query()->with(['workTimesheet', 'department']);

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
            'work_timesheet_id' => ['required', 'uuid', 'exists:work_timesheet,id'],
            'department_id' => ['required', 'integer', 'exists:department,id'],
            'effective_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $assignment = WorkTimesheetDepartment::create([
            'id' => (string) Str::uuid(),
            'work_timesheet_id' => $validated['work_timesheet_id'],
            'department_id' => $validated['department_id'],
            'effective_date' => $validated['effective_date'],
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json($assignment, 201);
    }
}
