<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\WorkTimesheet;
use App\Models\WorkTimesheetDepartment;
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
            'currentWorkTimesheetAssignment.workTimesheet',
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

        if (in_array($sortField, ['name'])) {
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
                'work_timesheet_id' => 'nullable|uuid|exists:work_timesheet,id',
            ]);

            $department = Department::create([
                'name' => $validatedData['name'],
                'parentId' => $validatedData['parentId'] ?? null,
            ]);

            $this->assignWorkTimesheet(
                $department,
                $validatedData['work_timesheet_id'] ?? null,
            );

            return response()->json([
                'message' => 'Department created successfully',
                'data' => $department->load(['parent', 'currentWorkTimesheetAssignment.workTimesheet'])
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
        $this->ensureDefaultWorkTimesheetAssignment($department);

        return response()->json(
            $department->load(['parent', 'children', 'currentWorkTimesheetAssignment.workTimesheet']),
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
                'work_timesheet_id' => 'sometimes|nullable|uuid|exists:work_timesheet,id',
            ]);

            // Prevent circular reference
            if (isset($validatedData['parentId']) && $validatedData['parentId'] == $department->id) {
                return response()->json([
                    'error' => 'A department cannot be its own parent'
                ], 422);
            }

            $department->update(collect($validatedData)->only(['name', 'parentId'])->all());

            if (array_key_exists('work_timesheet_id', $validatedData)) {
                $this->assignWorkTimesheet(
                    $department,
                    $validatedData['work_timesheet_id'] ?? null,
                );
            } else {
                $this->ensureDefaultWorkTimesheetAssignment($department);
            }

            return response()->json([
                'message' => 'Department updated successfully',
                'data' => $department->load(['parent', 'currentWorkTimesheetAssignment.workTimesheet'])
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

    private function assignWorkTimesheet(
        Department $department,
        ?string $workTimesheetId
    ): void {
        WorkTimesheetDepartment::create([
            'work_timesheet_id' => $workTimesheetId ?: $this->defaultWorkTimesheet()->id,
            'department_id' => $department->id,
            'effective_date' => now()->toDateString(),
        ]);
    }

    private function ensureDefaultWorkTimesheetAssignment(Department $department): void
    {
        if ($department->workTimesheetAssignments()->exists()) {
            return;
        }

        $this->assignWorkTimesheet(
            $department,
            null,
        );
    }

    private function defaultWorkTimesheet(): WorkTimesheet
    {
        return WorkTimesheet::firstOrCreate(
            ['name' => 'Default Timesheet'],
            [
                'start_time' => '08:00',
                'end_time' => '17:00',
                'break_minutes' => 60,
                'days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
                'is_active' => true,
            ],
        );
    }
}
