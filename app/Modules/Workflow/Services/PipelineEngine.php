<?php

namespace App\Modules\Workflow\Services;

use App\Enums\PipelineAssigneeType;
use App\Enums\PipelineInstanceStatus;
use App\Models\PipelineAction;
use App\Models\PipelineInstance;
use App\Models\PipelineTemplate;
use App\Models\PipelineTemplateStep;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PipelineEngine
{
    public const SUBJECT_LEAVE = 'leave_request';

    public const SUBJECT_TIMESHEET = 'timesheet';

    /**
     * @return array{instance: PipelineInstance, domain_status: ?string}
     */
    public function start(
        string $subjectType,
        string $subjectId,
        ?User $actor = null,
        ?string $templateKey = null,
    ): array {
        $template = $this->resolveTemplate($subjectType, $templateKey);
        $firstStep = $template->steps->first();

        if (! $firstStep) {
            throw new InvalidArgumentException("Pipeline template [{$template->key}] has no steps.");
        }

        $existing = PipelineInstance::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('status', PipelineInstanceStatus::InProgress->value)
            ->first();

        if ($existing) {
            $existing->load(['template.steps', 'currentStep']);

            return [
                'instance' => $existing,
                'domain_status' => $existing->currentStep?->domain_status,
            ];
        }

        $instance = PipelineInstance::query()->create([
            'pipeline_template_id' => $template->id,
            'current_step_id' => $firstStep->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'status' => PipelineInstanceStatus::InProgress->value,
            'started_by_user_id' => $actor?->id,
        ]);

        $instance->load(['template.steps', 'currentStep']);

        return [
            'instance' => $instance,
            'domain_status' => $firstStep->domain_status,
        ];
    }

    /**
     * @return array{
     *   instance: PipelineInstance,
     *   domain_status: ?string,
     *   completed: bool,
     *   action: string
     * }
     */
    public function transition(
        string $subjectType,
        string $subjectId,
        string $action,
        ?User $actor = null,
        ?string $note = null,
    ): array {
        $action = strtolower(trim($action));
        $instance = $this->activeInstance($subjectType, $subjectId);

        if (! $instance) {
            $started = $this->start($subjectType, $subjectId, $actor);
            $instance = $started['instance'];
        }

        $instance->loadMissing(['template.steps', 'currentStep']);
        $currentStep = $instance->currentStep;

        if (! $currentStep || $instance->status !== PipelineInstanceStatus::InProgress->value) {
            abort(422, 'Pipeline is not awaiting an action.');
        }

        $allowed = collect($currentStep->actions ?? ['approve', 'reject'])
            ->map(fn ($value) => strtolower((string) $value))
            ->all();

        if (! in_array($action, $allowed, true) && ! in_array($action, ['cancel'], true)) {
            abort(422, "Action [{$action}] is not allowed on the current pipeline step.");
        }

        return DB::transaction(function () use ($instance, $currentStep, $action, $actor, $note) {
            $steps = $instance->template->steps->values();
            $currentIndex = $steps->search(fn (PipelineTemplateStep $step) => $step->id === $currentStep->id);
            $nextStep = ($currentIndex !== false) ? $steps->get($currentIndex + 1) : null;

            $completed = false;
            $toStepId = null;
            $domainStatus = $currentStep->domain_status;
            $instanceStatus = PipelineInstanceStatus::InProgress->value;

            if ($action === 'approve') {
                if ($nextStep) {
                    $toStepId = $nextStep->id;
                    $domainStatus = $nextStep->domain_status;
                    $instance->current_step_id = $nextStep->id;
                } else {
                    $completed = true;
                    $instanceStatus = PipelineInstanceStatus::Completed->value;
                    $domainStatus = $instance->template->completion_status;
                    $instance->current_step_id = null;
                    $instance->completed_at = now();
                }
            } elseif ($action === 'reject') {
                $completed = true;
                $instanceStatus = PipelineInstanceStatus::Rejected->value;
                $domainStatus = $instance->template->rejection_status;
                $instance->current_step_id = null;
                $instance->completed_at = now();
            } elseif ($action === 'cancel') {
                $completed = true;
                $instanceStatus = PipelineInstanceStatus::Cancelled->value;
                $domainStatus = $instance->template->cancellation_status;
                $instance->current_step_id = null;
                $instance->completed_at = now();
            } elseif ($action === 'return') {
                $previous = ($currentIndex !== false && $currentIndex > 0)
                    ? $steps->get($currentIndex - 1)
                    : null;
                if (! $previous) {
                    abort(422, 'Cannot return from the first pipeline step.');
                }
                $toStepId = $previous->id;
                $domainStatus = $previous->domain_status;
                $instance->current_step_id = $previous->id;
            } else {
                abort(422, "Unsupported pipeline action [{$action}].");
            }

            $instance->status = $instanceStatus;
            $instance->save();

            PipelineAction::query()->create([
                'pipeline_instance_id' => $instance->id,
                'from_step_id' => $currentStep->id,
                'to_step_id' => $toStepId,
                'actor_user_id' => $actor?->id,
                'action' => $action,
                'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                'resulting_status' => $domainStatus,
            ]);

            $instance->load(['template.steps', 'currentStep', 'actions']);

            return [
                'instance' => $instance,
                'domain_status' => $domainStatus,
                'completed' => $completed,
                'action' => $action,
            ];
        });
    }

    public function activeInstance(string $subjectType, string $subjectId): ?PipelineInstance
    {
        return PipelineInstance::query()
            ->with(['template.steps', 'currentStep'])
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('status', PipelineInstanceStatus::InProgress->value)
            ->latest('created_at')
            ->first();
    }

    public function latestInstance(string $subjectType, string $subjectId): ?PipelineInstance
    {
        return PipelineInstance::query()
            ->with(['template.steps', 'currentStep', 'actions'])
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->latest('created_at')
            ->first();
    }

    public function resolveTemplate(string $subjectType, ?string $templateKey = null): PipelineTemplate
    {
        $query = PipelineTemplate::query()
            ->with('steps')
            ->where('is_active', true)
            ->where('subject_type', $subjectType);

        if ($templateKey) {
            $query->where('key', $templateKey);
        }

        $template = $query->orderBy('name')->first();

        if (! $template) {
            throw new InvalidArgumentException("No active pipeline template found for [{$subjectType}].");
        }

        return $template;
    }

    public function initialDomainStatus(string $subjectType, ?string $templateKey = null): ?string
    {
        $template = $this->resolveTemplate($subjectType, $templateKey);
        $firstStep = $template->steps->first();

        return $firstStep?->domain_status;
    }

    public function currentAssigneeType(PipelineInstance $instance): ?PipelineAssigneeType
    {
        $type = $instance->currentStep?->assignee_type;
        if (! $type) {
            return null;
        }

        return PipelineAssigneeType::tryFrom($type);
    }

    /**
     * @return array<string, mixed>
     */
    public function transformTemplate(PipelineTemplate $template): array
    {
        $template->loadMissing('steps');

        return [
            'id' => $template->id,
            'key' => $template->key,
            'name' => $template->name,
            'subjectType' => $template->subject_type,
            'description' => $template->description,
            'completionStatus' => $template->completion_status,
            'rejectionStatus' => $template->rejection_status,
            'cancellationStatus' => $template->cancellation_status,
            'isActive' => (bool) $template->is_active,
            'steps' => $template->steps->map(fn (PipelineTemplateStep $step) => $this->transformStep($step))->values()->all(),
            'createdAt' => $template->created_at?->toIso8601String(),
            'updatedAt' => $template->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function transformStep(PipelineTemplateStep $step): array
    {
        return [
            'id' => $step->id,
            'key' => $step->key,
            'label' => $step->label,
            'sortOrder' => (int) $step->sort_order,
            'assigneeType' => $step->assignee_type,
            'assigneeRole' => $step->assignee_role,
            'domainStatus' => $step->domain_status,
            'actions' => $step->actions ?? ['approve', 'reject'],
            'uiConfig' => $step->ui_config ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function transformInstance(PipelineInstance $instance): array
    {
        $instance->loadMissing(['template.steps', 'currentStep', 'actions']);

        return [
            'id' => $instance->id,
            'status' => $instance->status,
            'subjectType' => $instance->subject_type,
            'subjectId' => $instance->subject_id,
            'template' => $instance->template
                ? $this->transformTemplate($instance->template)
                : null,
            'currentStep' => $instance->currentStep
                ? $this->transformStep($instance->currentStep)
                : null,
            'completedAt' => $instance->completed_at?->toIso8601String(),
            'actions' => $instance->actions->map(fn (PipelineAction $action) => [
                'id' => $action->id,
                'action' => $action->action,
                'note' => $action->note,
                'resultingStatus' => $action->resulting_status,
                'actorUserId' => $action->actor_user_id,
                'createdAt' => $action->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
