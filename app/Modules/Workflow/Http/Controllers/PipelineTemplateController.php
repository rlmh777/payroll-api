<?php

namespace App\Modules\Workflow\Http\Controllers;

use App\Enums\PipelineAssigneeType;
use App\Http\Controllers\Controller;
use App\Models\PipelineTemplate;
use App\Models\PipelineTemplateStep;
use App\Modules\Workflow\Services\PipelineEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PipelineTemplateController extends Controller
{
    public function __construct(
        private readonly PipelineEngine $pipelineEngine,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = PipelineTemplate::query()->with('steps')->orderBy('name');

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        if ($request->filled('subject_type')) {
            $query->where('subject_type', $request->string('subject_type'));
        }

        $rows = $query->get()->map(fn (PipelineTemplate $template) => $this->pipelineEngine->transformTemplate($template));

        return response()->json($rows->values());
    }

    public function show(PipelineTemplate $pipelineTemplate): JsonResponse
    {
        return response()->json($this->pipelineEngine->transformTemplate($pipelineTemplate->load('steps')));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $template = DB::transaction(function () use ($data) {
            $template = PipelineTemplate::query()->create([
                'key' => $data['key'],
                'name' => $data['name'],
                'subject_type' => $data['subjectType'],
                'description' => $data['description'] ?? null,
                'completion_status' => $data['completionStatus'] ?? null,
                'rejection_status' => $data['rejectionStatus'] ?? null,
                'cancellation_status' => $data['cancellationStatus'] ?? null,
                'is_active' => (bool) ($data['isActive'] ?? true),
            ]);

            $this->syncSteps($template, $data['steps'] ?? []);

            return $template->fresh('steps');
        });

        return response()->json($this->pipelineEngine->transformTemplate($template), Response::HTTP_CREATED);
    }

    public function update(Request $request, PipelineTemplate $pipelineTemplate): JsonResponse
    {
        $data = $this->validated($request, $pipelineTemplate);

        $template = DB::transaction(function () use ($pipelineTemplate, $data) {
            $pipelineTemplate->update([
                'key' => $data['key'],
                'name' => $data['name'],
                'subject_type' => $data['subjectType'],
                'description' => $data['description'] ?? null,
                'completion_status' => $data['completionStatus'] ?? null,
                'rejection_status' => $data['rejectionStatus'] ?? null,
                'cancellation_status' => $data['cancellationStatus'] ?? null,
                'is_active' => (bool) ($data['isActive'] ?? true),
            ]);

            $this->syncSteps($pipelineTemplate, $data['steps'] ?? []);

            return $pipelineTemplate->fresh('steps');
        });

        return response()->json($this->pipelineEngine->transformTemplate($template));
    }

    public function destroy(PipelineTemplate $pipelineTemplate): JsonResponse
    {
        if ($pipelineTemplate->instances()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a pipeline template that has instances. Deactivate it instead.',
            ], 422);
        }

        $pipelineTemplate->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function meta(): JsonResponse
    {
        return response()->json([
            'subjectTypes' => [
                ['value' => PipelineEngine::SUBJECT_LEAVE, 'label' => 'Leave request'],
                ['value' => PipelineEngine::SUBJECT_TIMESHEET, 'label' => 'Timesheet'],
            ],
            'assigneeTypes' => [
                ['value' => PipelineAssigneeType::Supervisor->value, 'label' => 'Supervisor'],
                ['value' => PipelineAssigneeType::DepartmentHead->value, 'label' => 'Department head'],
                ['value' => PipelineAssigneeType::Admin->value, 'label' => 'Administrator'],
                ['value' => PipelineAssigneeType::Role->value, 'label' => 'Role'],
                ['value' => PipelineAssigneeType::SubjectOwner->value, 'label' => 'Subject owner'],
            ],
            'actions' => [
                ['value' => 'approve', 'label' => 'Approve'],
                ['value' => 'reject', 'label' => 'Reject'],
                ['value' => 'return', 'label' => 'Return'],
                ['value' => 'cancel', 'label' => 'Cancel'],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?PipelineTemplate $existing = null): array
    {
        return $request->validate([
            'key' => [
                'required',
                'string',
                'max:64',
                'alpha_dash',
                Rule::unique('pipeline_templates', 'key')->ignore($existing?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'subjectType' => ['required', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:2000'],
            'completionStatus' => ['nullable', 'string', 'max:64'],
            'rejectionStatus' => ['nullable', 'string', 'max:64'],
            'cancellationStatus' => ['nullable', 'string', 'max:64'],
            'isActive' => ['sometimes', 'boolean'],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*.id' => ['nullable', 'uuid'],
            'steps.*.key' => ['required', 'string', 'max:64', 'alpha_dash'],
            'steps.*.label' => ['required', 'string', 'max:255'],
            'steps.*.sortOrder' => ['nullable', 'integer', 'min:0'],
            'steps.*.assigneeType' => ['required', 'string', Rule::in(PipelineAssigneeType::values())],
            'steps.*.assigneeRole' => ['nullable', 'string', 'max:255'],
            'steps.*.domainStatus' => ['nullable', 'string', 'max:64'],
            'steps.*.actions' => ['nullable', 'array'],
            'steps.*.actions.*' => ['string', 'max:32'],
            'steps.*.uiConfig' => ['nullable', 'array'],
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $steps
     */
    private function syncSteps(PipelineTemplate $template, array $steps): void
    {
        $keepIds = [];

        foreach (array_values($steps) as $index => $stepData) {
            $payload = [
                'key' => $stepData['key'],
                'label' => $stepData['label'],
                'sort_order' => (int) ($stepData['sortOrder'] ?? ($index + 1)),
                'assignee_type' => $stepData['assigneeType'],
                'assignee_role' => $stepData['assigneeRole'] ?? null,
                'domain_status' => $stepData['domainStatus'] ?? null,
                'actions' => $stepData['actions'] ?? ['approve', 'reject'],
                'ui_config' => $stepData['uiConfig'] ?? [],
            ];

            $stepId = $stepData['id'] ?? null;
            if ($stepId) {
                $step = PipelineTemplateStep::query()
                    ->where('pipeline_template_id', $template->id)
                    ->where('id', $stepId)
                    ->first();
                if ($step) {
                    $step->update($payload);
                    $keepIds[] = $step->id;
                    continue;
                }
            }

            $created = $template->steps()->create($payload);
            $keepIds[] = $created->id;
        }

        $template->steps()
            ->when($keepIds !== [], fn ($query) => $query->whereNotIn('id', $keepIds), fn ($query) => $query)
            ->delete();
    }
}
