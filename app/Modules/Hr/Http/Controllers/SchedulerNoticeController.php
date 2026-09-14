<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Models\SchedulerNotice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SchedulerNoticeController extends Controller
{
    private const AUDIENCE_TYPES = [
        SchedulerNotice::AUDIENCE_COMPANY,
        SchedulerNotice::AUDIENCE_DEPARTMENTS,
        SchedulerNotice::AUDIENCE_EMPLOYEES,
    ];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start' => ['nullable', 'date'],
            'end' => ['nullable', 'date', 'after_or_equal:start'],
            'active_only' => ['sometimes', 'boolean'],
        ]);

        $query = SchedulerNotice::query()
            ->with(['departments:id,name', 'employees:id,code,person_id', 'employees.person:id,firstName,lastName'])
            ->orderBy('start_date')
            ->orderBy('title');

        if (($validated['active_only'] ?? true) !== false) {
            $query->where('is_active', true);
        }

        if (! empty($validated['start']) && ! empty($validated['end'])) {
            $query->whereDate('start_date', '<=', $validated['end'])
                ->whereDate('end_date', '>=', $validated['start']);
        }

        $notices = $query->get()->map(fn (SchedulerNotice $notice) => $this->transform($notice));

        return response()->json(['data' => $notices]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);
        $userId = $request->user()?->id;

        $notice = DB::transaction(function () use ($validated, $userId) {
            $notice = SchedulerNotice::create([
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'audience_type' => $validated['audience_type'],
                'color' => $validated['color'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
                'created_by_id' => $userId,
                'updated_by_id' => $userId,
            ]);

            $this->syncAudience($notice, $validated);

            return $notice->fresh()->load([
                'departments:id,name',
                'employees:id,code,person_id',
                'employees.person:id,firstName,lastName',
            ]);
        });

        return response()->json([
            'message' => 'Scheduler notice created successfully',
            'data' => $this->transform($notice),
        ], Response::HTTP_CREATED);
    }

    public function show(SchedulerNotice $schedulerNotice): JsonResponse
    {
        $schedulerNotice->load([
            'departments:id,name',
            'employees:id,code,person_id',
            'employees.person:id,firstName,lastName',
        ]);

        return response()->json($this->transform($schedulerNotice));
    }

    public function update(Request $request, SchedulerNotice $schedulerNotice): JsonResponse
    {
        $validated = $this->validatePayload($request, partial: true);
        $userId = $request->user()?->id;

        $notice = DB::transaction(function () use ($schedulerNotice, $validated, $userId) {
            $schedulerNotice->update([
                ...collect($validated)->only([
                    'title',
                    'description',
                    'start_date',
                    'end_date',
                    'audience_type',
                    'color',
                    'is_active',
                ])->all(),
                'updated_by_id' => $userId,
            ]);

            if (isset($validated['audience_type'])
                || array_key_exists('department_ids', $validated)
                || array_key_exists('employee_ids', $validated)
            ) {
                $payload = [
                    'audience_type' => $validated['audience_type'] ?? $schedulerNotice->audience_type,
                    'department_ids' => $validated['department_ids']
                        ?? $schedulerNotice->departments()->pluck('department.id')->all(),
                    'employee_ids' => $validated['employee_ids']
                        ?? $schedulerNotice->employees()->pluck('employee.id')->all(),
                ];
                $this->assertAudienceTargets($payload);
                $this->syncAudience($schedulerNotice, $payload);
            }

            return $schedulerNotice->fresh()->load([
                'departments:id,name',
                'employees:id,code,person_id',
                'employees.person:id,firstName,lastName',
            ]);
        });

        return response()->json([
            'message' => 'Scheduler notice updated successfully',
            'data' => $this->transform($notice),
        ]);
    }

    public function destroy(SchedulerNotice $schedulerNotice): JsonResponse
    {
        $schedulerNotice->delete();

        return response()->json(['message' => 'Scheduler notice deleted successfully']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $validated = $request->validate([
            'title' => ["{$required}", 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'start_date' => ["{$required}", 'date'],
            'end_date' => ["{$required}", 'date', 'after_or_equal:start_date'],
            'audience_type' => ["{$required}", Rule::in(self::AUDIENCE_TYPES)],
            'color' => ['nullable', 'string', 'max:32'],
            'is_active' => ['sometimes', 'boolean'],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer', 'exists:department,id'],
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['uuid', 'exists:employee,id'],
        ]);

        if (! $partial || isset($validated['audience_type'])
            || array_key_exists('department_ids', $validated)
            || array_key_exists('employee_ids', $validated)
        ) {
            $this->assertAudienceTargets([
                'audience_type' => $validated['audience_type']
                    ?? $request->input('audience_type'),
                'department_ids' => $validated['department_ids'] ?? [],
                'employee_ids' => $validated['employee_ids'] ?? [],
            ]);
        }

        if (isset($validated['title'])) {
            $validated['title'] = trim((string) $validated['title']);
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertAudienceTargets(array $payload): void
    {
        $type = $payload['audience_type'] ?? null;
        $departmentIds = $payload['department_ids'] ?? [];
        $employeeIds = $payload['employee_ids'] ?? [];

        if ($type === SchedulerNotice::AUDIENCE_DEPARTMENTS && count($departmentIds) === 0) {
            throw ValidationException::withMessages([
                'department_ids' => ['Select at least one department.'],
            ]);
        }

        if ($type === SchedulerNotice::AUDIENCE_EMPLOYEES && count($employeeIds) === 0) {
            throw ValidationException::withMessages([
                'employee_ids' => ['Select at least one employee.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function syncAudience(SchedulerNotice $notice, array $payload): void
    {
        $type = $payload['audience_type'];

        if ($type === SchedulerNotice::AUDIENCE_DEPARTMENTS) {
            $notice->departments()->sync($payload['department_ids'] ?? []);
            $notice->employees()->sync([]);
            return;
        }

        if ($type === SchedulerNotice::AUDIENCE_EMPLOYEES) {
            $notice->employees()->sync($payload['employee_ids'] ?? []);
            $notice->departments()->sync([]);
            return;
        }

        $notice->departments()->sync([]);
        $notice->employees()->sync([]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(SchedulerNotice $notice): array
    {
        return [
            'id' => $notice->id,
            'title' => $notice->title,
            'description' => $notice->description,
            'start_date' => $notice->start_date?->format('Y-m-d'),
            'end_date' => $notice->end_date?->format('Y-m-d'),
            'audience_type' => $notice->audience_type,
            'color' => $notice->color,
            'is_active' => (bool) $notice->is_active,
            'department_ids' => $notice->departments->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'departments' => $notice->departments->map(fn ($department) => [
                'id' => (int) $department->id,
                'name' => $department->name,
            ])->values()->all(),
            'employee_ids' => $notice->employees->pluck('id')->values()->all(),
            'employees' => $notice->employees->map(function ($employee) {
                $firstName = (string) ($employee->person?->firstName ?? $employee->firstName ?? '');
                $lastName = (string) ($employee->person?->lastName ?? $employee->lastName ?? '');
                $display = trim($lastName.($lastName && $firstName ? ', ' : '').$firstName);

                return [
                    'id' => $employee->id,
                    'code' => $employee->code,
                    'display_name' => $display !== '' ? $display : ($employee->code ?? 'Employee'),
                ];
            })->values()->all(),
            'created_at' => $notice->created_at?->toISOString(),
            'updated_at' => $notice->updated_at?->toISOString(),
        ];
    }
}
