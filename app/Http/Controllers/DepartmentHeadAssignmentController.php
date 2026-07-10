<?php

namespace App\Http\Controllers;

use App\Models\DepartmentHeadAssignment;
use App\Services\Department\DepartmentHeadAssignmentService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentHeadAssignmentController extends Controller
{
    private const RELATIONS = [
        'department:id,name',
        'employee:id,code,firstName,lastName',
        'appointedBy:id,firstName,lastName',
    ];

    public function __construct(
        private readonly DepartmentHeadAssignmentService $assignmentService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = DepartmentHeadAssignment::query()->with(self::RELATIONS);

        if ($request->filled('departmentId')) {
            $query->where('departmentId', $request->integer('departmentId'));
        }

        if ($request->filled('employeeId')) {
            $query->where('employeeId', $request->string('employeeId'));
        }

        if ($request->has('isCurrent')) {
            $query->where('isCurrent', $request->boolean('isCurrent'));
        }

        if ($request->filled('search')) {
            $search = '%'.$request->string('search').'%';
            $query->where(function ($builder) use ($search) {
                $builder
                    ->whereHas('department', fn ($department) => $department->where('name', 'ilike', $search))
                    ->orWhereHas('employee', function ($employee) use ($search) {
                        $employee
                            ->where('firstName', 'ilike', $search)
                            ->orWhere('lastName', 'ilike', $search)
                            ->orWhere('code', 'ilike', $search);
                    });
            });
        }

        $sortField = $request->input('sortBy', 'startDate');
        $sortDirection = $request->input('sortDirection', 'desc');
        $query->orderBy($sortField, $sortDirection);

        $paginator = $query->paginate((int) $request->input('per_page', 15));
        $paginator->getCollection()->transform(fn (DepartmentHeadAssignment $assignment) => $this->transformAssignment($assignment));

        return response()->json($paginator);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'departmentId' => ['required', 'integer', 'exists:department,id'],
            'employeeId' => ['required', 'uuid', 'exists:employee,id'],
            'startDate' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'appointedById' => ['nullable', 'uuid', 'exists:employee,id'],
        ]);

        $validated['appointedById'] = $validated['appointedById']
            ?? $this->assignmentService->resolveAppointedById($request->user()?->id);

        $assignment = $this->assignmentService
            ->appoint($validated)
            ->load(self::RELATIONS);

        return response()->json([
            'message' => 'Department head appointed.',
            'data' => $this->transformAssignment($assignment),
        ], 201);
    }

    public function show(DepartmentHeadAssignment $departmentHeadAssignment): JsonResponse
    {
        return response()->json([
            'data' => $this->transformAssignment($departmentHeadAssignment->load(self::RELATIONS)),
        ]);
    }

    public function update(Request $request, DepartmentHeadAssignment $departmentHeadAssignment): JsonResponse
    {
        $validated = $request->validate([
            'notes' => ['nullable', 'string'],
            'endDate' => ['nullable', 'date'],
        ]);

        if (!empty($validated['endDate'])) {
            $departmentHeadAssignment = $this->assignmentService->endAssignment(
                $departmentHeadAssignment,
                Carbon::parse($validated['endDate'])->startOfDay(),
            );
        } elseif (array_key_exists('notes', $validated)) {
            $departmentHeadAssignment->notes = $validated['notes'];
            $departmentHeadAssignment->save();
            $departmentHeadAssignment->load(self::RELATIONS);
        }

        return response()->json([
            'message' => 'Department head assignment updated.',
            'data' => $this->transformAssignment($departmentHeadAssignment),
        ]);
    }

    public function destroy(DepartmentHeadAssignment $departmentHeadAssignment): JsonResponse
    {
        if ($departmentHeadAssignment->isCurrent && $departmentHeadAssignment->endDate === null) {
            return response()->json([
                'message' => 'End the current assignment instead of deleting it.',
            ], 422);
        }

        $departmentHeadAssignment->delete();

        return response()->json(['message' => 'Department head assignment deleted.']);
    }

    private function transformAssignment(DepartmentHeadAssignment $assignment): array
    {
        $employeeName = trim(sprintf(
            '%s %s',
            $assignment->employee?->firstName ?? '',
            $assignment->employee?->lastName ?? ''
        ));

        $appointedByName = trim(sprintf(
            '%s %s',
            $assignment->appointedBy?->firstName ?? '',
            $assignment->appointedBy?->lastName ?? ''
        ));

        return [
            'id' => $assignment->id,
            'departmentId' => $assignment->departmentId,
            'departmentName' => $assignment->department?->name,
            'employeeId' => $assignment->employeeId,
            'employeeCode' => $assignment->employee?->code,
            'employeeName' => $employeeName !== '' ? $employeeName : null,
            'startDate' => $assignment->startDate?->format('Y-m-d'),
            'endDate' => $assignment->endDate?->format('Y-m-d'),
            'isCurrent' => (bool) $assignment->isCurrent,
            'appointedById' => $assignment->appointedById,
            'appointedByName' => $appointedByName !== '' ? $appointedByName : null,
            'notes' => $assignment->notes,
            'createdAt' => $assignment->created_at?->format('Y-m-d H:i:s'),
            'updatedAt' => $assignment->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
