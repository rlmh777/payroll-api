<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\TimesheetTemplate;
use App\Models\TimesheetTemplateDepartment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DepartmentController extends Controller
{
    /**
     * Display a listing of departments.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Department::query()->with([
            'parent',
            'currentTimesheetTemplateAssignment.timesheetTemplate',
        ]);

        // Search by name
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('name', 'ilike', "%{$search}%");
        }

        // Filter by parent department
        if ($request->has('parent_id')) {
            $query->where('parentId', $request->parent_id);
        }

        // Sorting
        $sortField = $request->get('sort_by', 'name');
        $sortDirection = $request->get('sort_direction', 'asc');

        if (in_array($sortField, ['name', 'totalDailyHoursBeforeOvertime', 'totalWeeklyHoursBeforeOvertime'])) {
            $query->orderBy($sortField, $sortDirection);
        } else {
            $query->orderBy('name', 'asc');
        }

        // Pagination
        $perPage = $request->get('per_page', 10);
        $departments = $query->paginate($perPage);

        return response()->json($departments);
    }

    /**
     * Store a newly created department.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:256',
                'parentId' => 'nullable|exists:department,id',
                'timesheet_template_id' => 'nullable|uuid|exists:timesheet_template,id',
                'totalDailyHoursBeforeOvertime' => 'nullable|numeric|min:0|max:24',
                'totalWeeklyHoursBeforeOvertime' => 'nullable|numeric|min:0|max:168',
                'includeLunchHour' => 'nullable|boolean',
            ]);

            $department = Department::create([
                'name' => $validatedData['name'],
                'parentId' => $validatedData['parentId'] ?? null,
                'totalDailyHoursBeforeOvertime' => $validatedData['totalDailyHoursBeforeOvertime'] ?? 9,
                'totalWeeklyHoursBeforeOvertime' => $validatedData['totalWeeklyHoursBeforeOvertime'] ?? 45,
                'includeLunchHour' => $validatedData['includeLunchHour'] ?? true,
            ]);

            $this->assignTimesheetTemplate(
                $department,
                $validatedData['timesheet_template_id'] ?? null,
            );

            return response()->json([
                'message' => 'Department created successfully',
                'data' => $department->load(['parent', 'currentTimesheetTemplateAssignment.timesheetTemplate'])
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    /**
     * Display the specified department.
     */
    public function show(Department $department): JsonResponse
    {
        $this->ensureDefaultTimesheetTemplateAssignment($department);

        return response()->json(
            $department->load(['parent', 'children', 'currentTimesheetTemplateAssignment.timesheetTemplate']),
            200
        );
    }

    /**
     * Update the specified department.
     */
    public function update(Request $request, Department $department): JsonResponse
    {
        try {
            // If request is empty, return early with current data
            if (empty($request->all())) {
                return response()->json([
                    'message' => 'No data provided for update',
                    'data' => $department
                ], 200);
            }

            $validatedData = $request->validate([
                'name' => 'sometimes|string|max:256',
                'parentId' => 'sometimes|nullable|exists:department,id',
                'timesheet_template_id' => 'sometimes|nullable|uuid|exists:timesheet_template,id',
                'totalDailyHoursBeforeOvertime' => 'sometimes|nullable|numeric|min:0|max:24',
                'totalWeeklyHoursBeforeOvertime' => 'sometimes|nullable|numeric|min:0|max:168',
                'includeLunchHour' => 'sometimes|boolean',
            ]);

            // Prevent circular reference
            if (isset($validatedData['parentId']) && $validatedData['parentId'] == $department->id) {
                return response()->json([
                    'error' => 'A department cannot be its own parent'
                ], 422);
            }

            $department->update(collect($validatedData)->only([
                'name',
                'parentId',
                'totalDailyHoursBeforeOvertime',
                'totalWeeklyHoursBeforeOvertime',
                'includeLunchHour',
            ])->all());

            if (array_key_exists('timesheet_template_id', $validatedData)) {
                $this->assignTimesheetTemplate(
                    $department,
                    $validatedData['timesheet_template_id'] ?? null,
                );
            } else {
                $this->ensureDefaultTimesheetTemplateAssignment($department);
            }

            return response()->json([
                'message' => 'Department updated successfully',
                'data' => $department->load(['parent', 'currentTimesheetTemplateAssignment.timesheetTemplate'])
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    /**
     * Remove the specified department.
     */
    public function destroy(Department $department): JsonResponse
    {
        // Check if department has children
        if ($department->children()->exists()) {
            return response()->json([
                'error' => 'Cannot delete department with child departments'
            ], 422);
        }

        // Check if department has employment details
        if ($department->employmentDetails()->exists()) {
            return response()->json([
                'error' => 'Cannot delete department with associated employment details'
            ], 422);
        }

        $department->delete();
        return response()->json(['message' => 'Department deleted successfully.']);
    }

    private function assignTimesheetTemplate(
        Department $department,
        ?string $timesheetTemplateId
    ): void {
        TimesheetTemplateDepartment::create([
            'timesheet_template_id' => $timesheetTemplateId ?: $this->defaultTimesheetTemplate()->id,
            'department_id' => $department->id,
            'effective_date' => now()->toDateString(),
        ]);
    }

    private function ensureDefaultTimesheetTemplateAssignment(Department $department): void
    {
        if ($department->timesheetTemplateAssignments()->exists()) {
            return;
        }

        $this->assignTimesheetTemplate(
            $department,
            null,
        );
    }

    private function defaultTimesheetTemplate(): TimesheetTemplate
    {
        return TimesheetTemplate::firstOrCreate(
            ['name' => 'Default Template'],
            [
                'start_time' => '08:00',
                'end_time' => '17:00',
                'break_minutes' => 60,
                'days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
                'day_schedules' => [
                    ['day' => 'Mon', 'start_time' => '08:00', 'end_time' => '17:00', 'include_lunch_hour' => false],
                    ['day' => 'Tue', 'start_time' => '08:00', 'end_time' => '17:00', 'include_lunch_hour' => false],
                    ['day' => 'Wed', 'start_time' => '08:00', 'end_time' => '17:00', 'include_lunch_hour' => false],
                    ['day' => 'Thu', 'start_time' => '08:00', 'end_time' => '17:00', 'include_lunch_hour' => false],
                    ['day' => 'Fri', 'start_time' => '08:00', 'end_time' => '17:00', 'include_lunch_hour' => false],
                ],
                'is_active' => true,
            ],
        );
    }
}
