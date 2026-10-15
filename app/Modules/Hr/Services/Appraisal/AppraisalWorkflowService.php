<?php

namespace App\Modules\Hr\Services\Appraisal;

use App\Models\PipelineTemplate;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeAppraisal;
use App\Modules\Hr\Models\FormResponse;
use App\Modules\Hr\Models\FormTemplate;
use App\Modules\Hr\Support\FormSchema;
use App\Modules\Workflow\Services\PipelineEngine;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class AppraisalWorkflowService
{
    public function __construct(
        private readonly PipelineEngine $pipelineEngine,
        private readonly AppraisalAssigneeResolver $assignees,
        private readonly AppraisalNotificationService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function start(array $payload, User $actor): EmployeeAppraisal
    {
        $employee = Employee::query()->with(['person', 'user'])->find($payload['employee_id'] ?? null);
        if (! $employee) {
            throw ValidationException::withMessages([
                'employee_id' => 'Select an employee for this appraisal.',
            ]);
        }

        $template = FormTemplate::query()
            ->where('id', $payload['form_template_id'] ?? null)
            ->where('is_active', true)
            ->first();
        if (! $template || $template->purpose !== FormTemplate::PURPOSE_APPRAISAL) {
            $template = FormTemplate::query()
                ->where('purpose', FormTemplate::PURPOSE_APPRAISAL)
                ->where('is_active', true)
                ->orderBy('name')
                ->first();
        }
        if (! $template) {
            throw ValidationException::withMessages([
                'form_template_id' => 'Create an appraisal form template before starting a review.',
            ]);
        }

        $pipelineKey = null;
        $pipelineId = $payload['pipeline_template_id'] ?? null;
        if ($pipelineId) {
            $pipeline = PipelineTemplate::query()
                ->where('id', $pipelineId)
                ->where('subject_type', PipelineEngine::SUBJECT_APPRAISAL)
                ->where('is_active', true)
                ->first();
            if (! $pipeline) {
                throw ValidationException::withMessages([
                    'pipeline_template_id' => 'Select an active employee appraisal pipeline.',
                ]);
            }
            $pipelineKey = $pipeline->key;
            $pipelineId = $pipeline->id;
        } else {
            try {
                $pipeline = $this->pipelineEngine->resolveTemplate(PipelineEngine::SUBJECT_APPRAISAL, 'employee_appraisal');
                $pipelineKey = $pipeline->key;
                $pipelineId = $pipeline->id;
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages([
                    'pipeline_template_id' => 'No active employee appraisal pipeline is configured.',
                ]);
            }
        }

        $appraisal = DB::transaction(function () use ($payload, $actor, $employee, $template, $pipelineId, $pipelineKey) {
            $response = FormResponse::query()->create([
                'form_template_id' => $template->id,
                'user_id' => $actor->id,
                'schema_snapshot' => $template->normalizedSchema(),
                'answers' => [],
            ]);

            $appraisal = EmployeeAppraisal::query()->create([
                'employee_id' => $employee->id,
                'form_template_id' => $template->id,
                'form_response_id' => $response->id,
                'pipeline_template_id' => $pipelineId,
                'initiated_by_user_id' => $actor->id,
                'period_label' => trim((string) ($payload['period_label'] ?? now()->year.' appraisal')),
                'period_start' => $payload['period_start'] ?? null,
                'period_end' => $payload['period_end'] ?? null,
                'status' => EmployeeAppraisal::STATUS_PENDING,
            ]);

            $this->pipelineEngine->start(
                PipelineEngine::SUBJECT_APPRAISAL,
                (string) $appraisal->id,
                $actor,
                $pipelineKey,
            );
            $this->skipUnresolvedSteps($appraisal, $actor);

            return $appraisal->fresh(['employee.person', 'formTemplate', 'formResponse']);
        });

        $this->notifications->notifyCurrentStep($appraisal);

        return $appraisal->fresh(['employee.person', 'formTemplate', 'formResponse']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function saveForm(EmployeeAppraisal $appraisal, User $actor, array $payload, bool $submit): FormResponse
    {
        $instance = $this->pipelineEngine->activeInstance(PipelineEngine::SUBJECT_APPRAISAL, (string) $appraisal->id);
        if (! $this->assignees->canEditForm($actor, $appraisal, $instance)) {
            abort(403, 'You cannot edit this appraisal form.');
        }

        $appraisal->loadMissing(['formTemplate', 'formResponse']);
        $template = $appraisal->formTemplate;
        $response = $appraisal->formResponse;
        if (! $template || ! $response) {
            abort(422, 'This appraisal does not have a form.');
        }

        $schema = is_array($response->schema_snapshot) && ($response->schema_snapshot['fields'] ?? []) !== []
            ? $response->schema_snapshot
            : $template->normalizedSchema();
        $answers = FormSchema::validateAnswers($schema, is_array($payload['answers'] ?? null) ? $payload['answers'] : []);
        $score = FormSchema::score($schema, $answers);

        $response->schema_snapshot = $schema;
        $response->answers = $answers;
        $response->comments = filled($payload['comments'] ?? null) ? trim((string) $payload['comments']) : $response->comments;
        $response->total_score = $score['total'];
        $response->max_score = $score['max'];
        $response->user_id = $actor->id;
        if ($submit) {
            $response->submitted_at = now();
            $appraisal->submitted_at = now();
            $appraisal->save();
        }
        $response->save();

        return $response->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function act(EmployeeAppraisal $appraisal, User $actor, array $payload): EmployeeAppraisal
    {
        $action = strtolower(trim((string) ($payload['action'] ?? '')));
        if ($action === '') {
            abort(422, 'Select an action.');
        }

        $instance = $this->pipelineEngine->activeInstance(PipelineEngine::SUBJECT_APPRAISAL, (string) $appraisal->id);
        if (! $this->assignees->canAct($actor, $appraisal, $instance)) {
            abort(403, 'You are not assigned to the current appraisal step.');
        }

        $step = $instance?->currentStep;
        if ($action === 'approve' && $this->assignees->isHrStep($step)) {
            $this->applyHrDecision($appraisal, $payload);
        }
        if ($action === 'approve' && $this->assignees->isAccountsStep($step) && array_key_exists('accounts_notes', $payload)) {
            $appraisal->accounts_notes = filled($payload['accounts_notes']) ? trim((string) $payload['accounts_notes']) : null;
            $appraisal->save();
        }
        if (in_array($action, ['approve', 'reject'], true) && filled($payload['note'] ?? null) && $this->assignees->isHrStep($step)) {
            $appraisal->hr_notes = trim((string) $payload['note']);
            $appraisal->save();
        }

        if ($action === 'approve') {
            $this->assertFormReady($appraisal);
        }

        $result = $this->pipelineEngine->transition(
            PipelineEngine::SUBJECT_APPRAISAL,
            (string) $appraisal->id,
            $action,
            $actor,
            isset($payload['note']) ? (string) $payload['note'] : null,
        );

        if ($result['completed']) {
            $appraisal->status = match ($action) {
                'reject' => EmployeeAppraisal::STATUS_REJECTED,
                'cancel' => EmployeeAppraisal::STATUS_CANCELLED,
                default => EmployeeAppraisal::STATUS_APPROVED,
            };
            $appraisal->completed_at = now();
            $appraisal->save();
            $this->notifications->notifyOutcome($appraisal);

            return $appraisal->fresh(['employee.person', 'formTemplate', 'formResponse']);
        }

        $this->skipUnresolvedSteps($appraisal, $actor);
        $appraisal->refresh();
        if ($appraisal->isOpen()) {
            $this->notifications->notifyCurrentStep($appraisal);
        } else {
            $this->notifications->notifyOutcome($appraisal);
        }

        return $appraisal->fresh(['employee.person', 'formTemplate', 'formResponse']);
    }

    public function flags(EmployeeAppraisal $appraisal, ?User $actor): array
    {
        $instance = $this->pipelineEngine->activeInstance(PipelineEngine::SUBJECT_APPRAISAL, (string) $appraisal->id);
        $step = $instance?->currentStep;

        return [
            'can_act' => $this->assignees->canAct($actor, $appraisal, $instance),
            'can_edit_form' => $this->assignees->canEditForm($actor, $appraisal, $instance),
            'can_manage' => Access::canManageAppraisals($actor),
            'is_hr_step' => $this->assignees->isHrStep($step),
            'is_accounts_step' => $this->assignees->isAccountsStep($step),
        ];
    }

    public function canView(?User $actor, EmployeeAppraisal $appraisal): bool
    {
        if (! $actor) {
            return false;
        }

        if (Access::canViewAppraisals($actor) || Access::canConfirmLeavePayment($actor)) {
            return true;
        }

        $appraisal->loadMissing('employee');
        if ($appraisal->employee?->user_id && (string) $appraisal->employee->user_id === (string) $actor->id) {
            return true;
        }

        $instance = $this->pipelineEngine->latestInstance(PipelineEngine::SUBJECT_APPRAISAL, (string) $appraisal->id);
        if ($this->assignees->canAct($actor, $appraisal, $this->pipelineEngine->activeInstance(PipelineEngine::SUBJECT_APPRAISAL, (string) $appraisal->id))) {
            return true;
        }

        if ($instance) {
            foreach ($instance->template?->steps ?? [] as $step) {
                if ($this->assignees->usersForStep($appraisal, $step)->contains(fn (User $user) => (string) $user->id === (string) $actor->id)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<EmployeeAppraisal>
     */
    public function inboxFor(User $actor): array
    {
        $rows = EmployeeAppraisal::query()
            ->with(['employee.person', 'formTemplate', 'formResponse'])
            ->where('status', EmployeeAppraisal::STATUS_PENDING)
            ->latest()
            ->get();

        return $rows
            ->filter(function (EmployeeAppraisal $appraisal) use ($actor) {
                $instance = $this->pipelineEngine->activeInstance(PipelineEngine::SUBJECT_APPRAISAL, (string) $appraisal->id);

                return $this->assignees->canAct($actor, $appraisal, $instance);
            })
            ->values()
            ->all();
    }

    private function applyHrDecision(EmployeeAppraisal $appraisal, array $payload): void
    {
        if (! array_key_exists('hr_renew_contract', $payload) || ! array_key_exists('hr_increment_approved', $payload)) {
            throw ValidationException::withMessages([
                'hr_renew_contract' => 'HR must recommend whether to renew the contract.',
                'hr_increment_approved' => 'HR must approve or decline an increment before sending this to accounts.',
            ]);
        }

        $renew = (bool) $payload['hr_renew_contract'];
        $increment = (bool) $payload['hr_increment_approved'];
        $type = $payload['hr_increment_type'] ?? null;
        $value = $payload['hr_increment_value'] ?? null;

        if ($increment) {
            if (! in_array($type, [EmployeeAppraisal::INCREMENT_PERCENT, EmployeeAppraisal::INCREMENT_AMOUNT], true)) {
                throw ValidationException::withMessages([
                    'hr_increment_type' => 'Choose whether the increment is a percent or an amount.',
                ]);
            }
            if (! is_numeric($value) || (float) $value <= 0) {
                throw ValidationException::withMessages([
                    'hr_increment_value' => 'Enter the approved increment.',
                ]);
            }
        } else {
            $type = null;
            $value = null;
        }

        $appraisal->hr_renew_contract = $renew;
        $appraisal->hr_increment_approved = $increment;
        $appraisal->hr_increment_type = $type;
        $appraisal->hr_increment_value = $value;
        if (array_key_exists('hr_notes', $payload) || array_key_exists('note', $payload)) {
            $notes = $payload['hr_notes'] ?? $payload['note'] ?? null;
            $appraisal->hr_notes = filled($notes) ? trim((string) $notes) : null;
        }
        $appraisal->save();
    }

    private function assertFormReady(EmployeeAppraisal $appraisal): void
    {
        $appraisal->loadMissing('formResponse');
        $response = $appraisal->formResponse;
        if (! $response) {
            return;
        }

        $schema = is_array($response->schema_snapshot) ? $response->schema_snapshot : ['fields' => []];
        $hasRequired = collect($schema['fields'] ?? [])
            ->contains(fn ($field) => is_array($field) && ($field['required'] ?? false) === true && ($field['type'] ?? '') !== 'section');

        if ($hasRequired && ! $response->submitted_at && ($response->answers ?? []) === []) {
            abort(422, 'Complete the appraisal form before approving this step.');
        }
    }

    private function skipUnresolvedSteps(EmployeeAppraisal $appraisal, ?User $actor): void
    {
        for ($i = 0; $i < 8; $i++) {
            $instance = $this->pipelineEngine->activeInstance(PipelineEngine::SUBJECT_APPRAISAL, (string) $appraisal->id);
            if (! $instance?->currentStep) {
                return;
            }

            if (! $this->assignees->canSkipIfUnresolved($instance->currentStep)) {
                return;
            }

            $users = $this->assignees->usersForCurrentStep($appraisal, $instance);
            if ($users->isNotEmpty()) {
                return;
            }

            $result = $this->pipelineEngine->transition(
                PipelineEngine::SUBJECT_APPRAISAL,
                (string) $appraisal->id,
                'approve',
                $actor,
                'Skipped: no assignee for this step.',
            );

            if ($result['completed']) {
                $appraisal->status = EmployeeAppraisal::STATUS_APPROVED;
                $appraisal->completed_at = now();
                $appraisal->save();

                return;
            }
        }
    }
}
