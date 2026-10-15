<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Models\ScheduledWork;
use App\Models\ShiftTemplate;
use App\Modules\Hr\Services\Attendance\ShiftTemplateAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ShiftTemplateController extends Controller
{
    private const RELATIONS = [
        'employee',
        'department',
        'worksite',
        'employmentDetail.department',
        'employmentDetail.worksite',
        'employmentDetail.contractType',
        'employmentDetail.defaultPayPeriodGroup',
    ];

    public function __construct(
        private readonly ShiftTemplateAssignmentService $assignmentService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'active_only' => ['sometimes', 'boolean'],
            'department_id' => ['sometimes', 'nullable', 'integer', 'exists:department,id'],
        ]);

        $query = ShiftTemplate::query()
            ->with(['departments:id,name'])
            ->orderByRaw('name IS NULL')
            ->orderBy('name');

        if (($validated['active_only'] ?? true) !== false) {
            $query->where('is_active', true);
        }

        if (! empty($validated['department_id'])) {
            $departmentId = (int) $validated['department_id'];
            $query->where(function ($builder) use ($departmentId) {
                $builder->whereDoesntHave('departments')
                    ->orWhereHas('departments', fn ($q) => $q->where('department.id', $departmentId));
            });
        }

        $templates = $query->get()->map(fn (ShiftTemplate $template) => $this->transform($template));

        return response()->json(['data' => $templates]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        $template = DB::transaction(function () use ($validated) {
            $template = ShiftTemplate::create([
                'id' => (string) Str::uuid(),
                'name' => $validated['name'],
                'segments' => $validated['segments'],
                'include_lunch_hour' => $validated['include_lunch_hour'],
                'lunch_hour_hours' => $validated['lunch_hour_hours'],
                'is_active' => $validated['is_active'],
            ]);

            $template->departments()->sync($validated['department_ids']);

            return $template->fresh()->load(['departments:id,name']);
        });

        return response()->json([
            'message' => 'Shift template created successfully',
            'data' => $this->transform($template),
        ], Response::HTTP_CREATED);
    }

    public function show(ShiftTemplate $shiftTemplate): JsonResponse
    {
        $shiftTemplate->load(['departments:id,name']);

        return response()->json($this->transform($shiftTemplate));
    }

    public function update(Request $request, ShiftTemplate $shiftTemplate): JsonResponse
    {
        $validated = $this->validatePayload($request, partial: true);

        $template = DB::transaction(function () use ($shiftTemplate, $validated) {
            $shiftTemplate->fill(collect($validated)->only([
                'name',
                'segments',
                'include_lunch_hour',
                'lunch_hour_hours',
                'is_active',
            ])->all());
            $shiftTemplate->save();

            if (array_key_exists('department_ids', $validated)) {
                $shiftTemplate->departments()->sync($validated['department_ids']);
            }

            return $shiftTemplate->fresh()->load(['departments:id,name']);
        });

        return response()->json([
            'message' => 'Shift template updated successfully',
            'data' => $this->transform($template),
        ]);
    }

    public function destroy(ShiftTemplate $shiftTemplate): JsonResponse
    {
        $shiftTemplate->delete();

        return response()->json(['message' => 'Shift template deleted successfully.']);
    }

    public function assign(Request $request, ShiftTemplate $shiftTemplate): JsonResponse
    {
        if (! $shiftTemplate->is_active) {
            throw ValidationException::withMessages([
                'shift_template' => ['This shift template is inactive.'],
            ]);
        }

        $validated = $request->validate([
            'employeeId' => ['required', 'uuid', 'exists:employee,id'],
            'date' => ['required', 'date'],
            'endDate' => ['nullable', 'date'],
            'employmentDetailId' => ['nullable', 'uuid', 'exists:employment_detail,id'],
            'departmentId' => ['nullable', 'integer', 'exists:department,id'],
            'worksiteId' => ['nullable', 'integer', 'exists:worksite,id'],
            'description' => ['nullable', 'string', 'max:1024'],
        ]);

        try {
            $created = $this->assignmentService->assign(
                $shiftTemplate,
                (string) $validated['employeeId'],
                (string) $validated['date'],
                isset($validated['endDate']) ? (string) $validated['endDate'] : null,
                $validated['employmentDetailId'] ?? null,
                isset($validated['departmentId']) ? (int) $validated['departmentId'] : null,
                isset($validated['worksiteId']) ? (int) $validated['worksiteId'] : null,
                $validated['description'] ?? null,
            );
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        $data = collect($created)
            ->map(fn (ScheduledWork $work) => $work->load(self::RELATIONS))
            ->values()
            ->all();

        return response()->json([
            'message' => 'Shift assigned successfully',
            'data' => $data,
        ], Response::HTTP_CREATED);
    }

    /**
     * @return array{
     *   name: string,
     *   segments: list<array{start_time: string, end_time: string}>,
     *   include_lunch_hour: bool,
     *   lunch_hour_hours: float,
     *   is_active: bool,
     *   department_ids: list<int>
     * }
     */
    private function validatePayload(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:160'],
            'segments' => ["{$required}", 'array', 'min:1'],
            'segments.*.start_time' => ['required_with:segments', 'date_format:H:i'],
            'segments.*.end_time' => ['required_with:segments', 'date_format:H:i'],
            'include_lunch_hour' => ['sometimes', 'boolean'],
            'lunch_hour_hours' => ['nullable', 'numeric', 'min:0', 'max:8'],
            'is_active' => ['sometimes', 'boolean'],
            'department_ids' => ['sometimes', 'array'],
            'department_ids.*' => ['integer', 'exists:department,id'],
        ]);

        if (isset($validated['segments'])) {
            $normalized = ShiftTemplate::normalizeSegments($validated['segments']);
            if ($normalized === []) {
                throw ValidationException::withMessages([
                    'segments' => ['At least one valid time segment is required.'],
                ]);
            }
            $validated['segments'] = $normalized;
        }

        if (! $partial || array_key_exists('include_lunch_hour', $validated)) {
            $validated['include_lunch_hour'] = (bool) ($validated['include_lunch_hour'] ?? false);
        }

        if (! $partial || array_key_exists('lunch_hour_hours', $validated)) {
            $validated['lunch_hour_hours'] = (float) ($validated['lunch_hour_hours'] ?? 1);
        }

        if (! $partial || array_key_exists('is_active', $validated)) {
            $validated['is_active'] = (bool) ($validated['is_active'] ?? true);
        }

        if (! $partial || array_key_exists('department_ids', $validated)) {
            $validated['department_ids'] = array_values(array_unique(array_map(
                'intval',
                $validated['department_ids'] ?? [],
            )));
        }

        if (! $partial || array_key_exists('name', $validated)) {
            $name = trim((string) ($validated['name'] ?? ''));
            $validated['name'] = $name === '' ? null : $name;
        }

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(ShiftTemplate $template): array
    {
        $departments = $template->relationLoaded('departments')
            ? $template->departments
            : $template->departments()->get(['department.id', 'department.name']);

        return [
            'id' => $template->id,
            'name' => $template->name,
            'display_label' => $template->displayLabel(),
            'segments' => $template->resolvedSegments(),
            'include_lunch_hour' => (bool) $template->include_lunch_hour,
            'lunch_hour_hours' => (float) ($template->lunch_hour_hours ?: 1),
            'is_active' => (bool) $template->is_active,
            'department_ids' => $departments->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'departments' => $departments->map(fn ($department) => [
                'id' => (int) $department->id,
                'name' => $department->name,
            ])->values()->all(),
        ];
    }
}
