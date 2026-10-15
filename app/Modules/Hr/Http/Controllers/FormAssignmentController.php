<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Models\PipelineTemplate;
use App\Modules\Hr\Models\FormAssignment;
use App\Modules\Hr\Models\FormTemplate;
use App\Modules\Workflow\Services\PipelineEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FormAssignmentController extends Controller
{
    public function index(FormTemplate $formTemplate): JsonResponse
    {
        $assignments = $formTemplate->assignments()
            ->with(['departments:id,name', 'employeeGroups:id,name', 'employees.person', 'pipelineTemplate', 'responses'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (FormAssignment $assignment) => $assignment->present())
            ->values();

        return response()->json(['data' => $assignments]);
    }

    public function store(Request $request, FormTemplate $formTemplate): JsonResponse
    {
        $assignment = DB::transaction(function () use ($request, $formTemplate) {
            $validated = $this->validatePayload($request);
            $assignment = $formTemplate->assignments()->create($this->attributes($validated));
            $this->syncAudience($assignment, $validated);

            return $assignment->load(['departments:id,name', 'employeeGroups:id,name', 'employees.person', 'pipelineTemplate', 'responses']);
        });

        return response()->json([
            'message' => 'Assignment created.',
            'data' => $assignment->present(),
        ], Response::HTTP_CREATED);
    }

    public function update(Request $request, FormAssignment $formAssignment): JsonResponse
    {
        $formAssignment = DB::transaction(function () use ($request, $formAssignment) {
            $validated = $this->validatePayload($request, $formAssignment);
            $formAssignment->update($this->attributes($validated, $formAssignment));
            $this->syncAudience($formAssignment, $validated);

            return $formAssignment->fresh(['departments:id,name', 'employeeGroups:id,name', 'employees.person', 'pipelineTemplate', 'responses']);
        });

        return response()->json([
            'message' => 'Assignment saved.',
            'data' => $formAssignment?->present(),
        ]);
    }

    public function destroy(FormAssignment $formAssignment): JsonResponse
    {
        $formAssignment->delete();

        return response()->json(['message' => 'Assignment deleted.']);
    }

    public function responses(FormAssignment $formAssignment): JsonResponse
    {
        $rows = $formAssignment->responses()
            ->with('user')
            ->orderByDesc('submitted_at')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn ($response) => $response->present())
            ->values();

        return response()->json([
            'assignment' => $formAssignment->load(['template', 'departments:id,name', 'employeeGroups:id,name', 'employees.person', 'pipelineTemplate'])->present(true),
            'data' => $rows,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?FormAssignment $existing = null): array
    {
        $required = $existing ? 'sometimes' : 'required';
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'audience_type' => [$required, 'string', Rule::in(FormAssignment::audienceTypes())],
            'show_on_login' => ['sometimes', 'boolean'],
            'is_required' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'pipeline_template_id' => ['nullable', 'uuid', 'exists:pipeline_templates,id'],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer', 'exists:department,id'],
            'employee_group_ids' => ['nullable', 'array'],
            'employee_group_ids.*' => ['uuid', 'exists:employee_group,id'],
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['uuid', 'exists:employee,id'],
        ]);

        $type = $validated['audience_type'] ?? $existing?->audience_type;
        if ($type === FormAssignment::AUDIENCE_DEPARTMENTS && count($validated['department_ids'] ?? []) === 0) {
            throw ValidationException::withMessages([
                'department_ids' => ['Select at least one department.'],
            ]);
        }
        if ($type === FormAssignment::AUDIENCE_EMPLOYEE_GROUPS && count($validated['employee_group_ids'] ?? []) === 0) {
            throw ValidationException::withMessages([
                'employee_group_ids' => ['Select at least one employee group.'],
            ]);
        }
        if ($type === FormAssignment::AUDIENCE_EMPLOYEES && count($validated['employee_ids'] ?? []) === 0) {
            throw ValidationException::withMessages([
                'employee_ids' => ['Select at least one employee.'],
            ]);
        }

        if (isset($validated['pipeline_template_id'])) {
            $template = PipelineTemplate::query()->find($validated['pipeline_template_id']);
            if ($template && $template->subject_type !== PipelineEngine::SUBJECT_FORM_RESPONSE) {
                throw ValidationException::withMessages([
                    'pipeline_template_id' => 'Choose a pipeline that reviews form responses.',
                ]);
            }
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributes(array $validated, ?FormAssignment $existing = null): array
    {
        return [
            'name' => array_key_exists('name', $validated)
                ? (filled($validated['name']) ? trim((string) $validated['name']) : null)
                : $existing?->name,
            'audience_type' => $validated['audience_type'] ?? $existing?->audience_type ?? FormAssignment::AUDIENCE_COMPANY,
            'show_on_login' => (bool) ($validated['show_on_login'] ?? $existing?->show_on_login ?? true),
            'is_required' => (bool) ($validated['is_required'] ?? $existing?->is_required ?? true),
            'is_active' => (bool) ($validated['is_active'] ?? $existing?->is_active ?? true),
            'starts_at' => $validated['starts_at'] ?? $existing?->starts_at,
            'ends_at' => $validated['ends_at'] ?? $existing?->ends_at,
            'pipeline_template_id' => array_key_exists('pipeline_template_id', $validated)
                ? $validated['pipeline_template_id']
                : $existing?->pipeline_template_id,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function syncAudience(FormAssignment $assignment, array $validated): void
    {
        $type = $assignment->audience_type;

        $assignment->departments()->sync(
            $type === FormAssignment::AUDIENCE_DEPARTMENTS ? ($validated['department_ids'] ?? []) : [],
        );
        $assignment->employeeGroups()->sync(
            $type === FormAssignment::AUDIENCE_EMPLOYEE_GROUPS ? ($validated['employee_group_ids'] ?? []) : [],
        );
        $assignment->employees()->sync(
            $type === FormAssignment::AUDIENCE_EMPLOYEES ? ($validated['employee_ids'] ?? []) : [],
        );
    }
}
