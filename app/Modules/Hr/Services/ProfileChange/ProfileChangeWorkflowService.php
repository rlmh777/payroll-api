<?php

namespace App\Modules\Hr\Services\ProfileChange;

use App\Models\Locality;
use App\Models\PipelineTemplate;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeProfileChangeRequest;
use App\Modules\Hr\Services\EmployeePersonSync;
use App\Modules\Workflow\Services\PipelineEngine;
use App\Support\Access;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ProfileChangeWorkflowService
{
    public function __construct(
        private readonly PipelineEngine $pipelineEngine,
        private readonly ProfileChangeAssigneeResolver $assignees,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function submit(Employee $employee, User $actor, array $payload): EmployeeProfileChangeRequest
    {
        $employee->loadMissing('person.locality');

        $open = EmployeeProfileChangeRequest::query()
            ->where('employee_id', $employee->id)
            ->where('status', EmployeeProfileChangeRequest::STATUS_PENDING)
            ->exists();
        if ($open) {
            throw ValidationException::withMessages([
                'proposed_values' => 'You already have a profile change waiting for approval.',
            ]);
        }

        $current = $this->snapshot($employee);
        $proposed = $this->diffProposed($payload, $current);
        if ($proposed === []) {
            throw ValidationException::withMessages([
                'proposed_values' => 'Change at least one of address, phone, or email before submitting.',
            ]);
        }

        $pipeline = $this->resolvePipeline($payload['pipeline_template_id'] ?? null);

        $request = DB::transaction(function () use ($employee, $actor, $current, $proposed, $pipeline) {
            $row = EmployeeProfileChangeRequest::query()->create([
                'employee_id' => $employee->id,
                'submitted_by_user_id' => $actor->id,
                'pipeline_template_id' => $pipeline->id,
                'status' => EmployeeProfileChangeRequest::STATUS_PENDING,
                'current_values' => $current,
                'proposed_values' => $proposed,
                'submitted_at' => now(),
            ]);

            $this->pipelineEngine->start(
                PipelineEngine::SUBJECT_PROFILE_CHANGE,
                (string) $row->id,
                $actor,
                $pipeline->key,
            );
            $this->skipUnresolvedSteps($row, $actor);

            return $row->fresh(['employee.person.locality']);
        });

        return $request;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function act(EmployeeProfileChangeRequest $request, User $actor, array $payload): EmployeeProfileChangeRequest
    {
        $action = strtolower(trim((string) ($payload['action'] ?? '')));
        if ($action === '') {
            abort(422, 'Select an action.');
        }

        $instance = $this->pipelineEngine->activeInstance(PipelineEngine::SUBJECT_PROFILE_CHANGE, (string) $request->id);

        if ($action === 'cancel') {
            if (! $this->assignees->canCancel($actor, $request)) {
                abort(403, 'You cannot cancel this profile change request.');
            }
        } elseif (! $this->assignees->canAct($actor, $request, $instance)) {
            abort(403, 'You are not assigned to the current profile-change step.');
        }

        if (filled($payload['note'] ?? null)) {
            $request->reviewer_notes = trim((string) $payload['note']);
            $request->save();
        }

        $result = $this->pipelineEngine->transition(
            PipelineEngine::SUBJECT_PROFILE_CHANGE,
            (string) $request->id,
            $action,
            $actor,
            isset($payload['note']) ? (string) $payload['note'] : null,
        );

        if ($result['completed']) {
            $request->status = match ($action) {
                'reject' => EmployeeProfileChangeRequest::STATUS_REJECTED,
                'cancel' => EmployeeProfileChangeRequest::STATUS_CANCELLED,
                default => EmployeeProfileChangeRequest::STATUS_APPROVED,
            };
            $request->completed_at = now();
            $request->save();

            if ($request->status === EmployeeProfileChangeRequest::STATUS_APPROVED) {
                $this->applyApprovedChange($request);
            }

            return $request->fresh(['employee.person.locality']);
        }

        $this->skipUnresolvedSteps($request, $actor);
        $request->refresh();

        if ($request->status === EmployeeProfileChangeRequest::STATUS_APPROVED) {
            $this->applyApprovedChange($request);
        }

        return $request->fresh(['employee.person.locality']);
    }

    /**
     * @return array<string, mixed>
     */
    public function flags(EmployeeProfileChangeRequest $request, ?User $actor): array
    {
        $instance = $this->pipelineEngine->activeInstance(PipelineEngine::SUBJECT_PROFILE_CHANGE, (string) $request->id);

        return [
            'can_act' => $this->assignees->canAct($actor, $request, $instance),
            'can_cancel' => $this->assignees->canCancel($actor, $request),
            'can_manage' => Access::canViewEmployeeDirectory($actor),
        ];
    }

    public function canView(?User $actor, EmployeeProfileChangeRequest $request): bool
    {
        if (! $actor) {
            return false;
        }

        if (Access::canViewEmployeeDirectory($actor) || Access::canActAsHrForProfileChange($actor)) {
            return true;
        }

        $request->loadMissing('employee');
        if ($request->employee?->user_id && (string) $request->employee->user_id === (string) $actor->id) {
            return true;
        }

        if ((string) $request->submitted_by_user_id === (string) $actor->id) {
            return true;
        }

        $instance = $this->pipelineEngine->latestInstance(PipelineEngine::SUBJECT_PROFILE_CHANGE, (string) $request->id);
        if ($this->assignees->canAct($actor, $request, $this->pipelineEngine->activeInstance(PipelineEngine::SUBJECT_PROFILE_CHANGE, (string) $request->id))) {
            return true;
        }

        if ($instance) {
            foreach ($instance->template?->steps ?? [] as $step) {
                if ($this->assignees->usersForStep($request->employee, $step)->contains(fn (User $user) => (string) $user->id === (string) $actor->id)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<EmployeeProfileChangeRequest>
     */
    public function inboxFor(User $actor): array
    {
        $rows = EmployeeProfileChangeRequest::query()
            ->with(['employee.person.locality'])
            ->where('status', EmployeeProfileChangeRequest::STATUS_PENDING)
            ->latest()
            ->get();

        return $rows
            ->filter(function (EmployeeProfileChangeRequest $request) use ($actor) {
                $instance = $this->pipelineEngine->activeInstance(PipelineEngine::SUBJECT_PROFILE_CHANGE, (string) $request->id);

                return $this->assignees->canAct($actor, $request, $instance);
            })
            ->values()
            ->all();
    }

    public function pendingForEmployee(Employee $employee, ?User $actor): ?array
    {
        $row = EmployeeProfileChangeRequest::query()
            ->where('employee_id', $employee->id)
            ->where('status', EmployeeProfileChangeRequest::STATUS_PENDING)
            ->latest()
            ->first();

        if (! $row) {
            return null;
        }

        return $row->present($this->pipelineEngine, $this->flags($row, $actor));
    }

    private function applyApprovedChange(EmployeeProfileChangeRequest $request): void
    {
        if ($request->applied_at) {
            return;
        }

        $request->loadMissing('employee.person');
        $employee = $request->employee;
        if (! $employee) {
            return;
        }

        $attributes = Arr::only($request->proposed_values ?? [], EmployeeProfileChangeRequest::CHANGEABLE_FIELDS);
        if ($attributes === []) {
            return;
        }

        EmployeePersonSync::update($employee, $attributes);
        $request->applied_at = now();
        $request->save();
    }

    private function skipUnresolvedSteps(EmployeeProfileChangeRequest $request, ?User $actor): void
    {
        for ($i = 0; $i < 8; $i++) {
            $instance = $this->pipelineEngine->activeInstance(PipelineEngine::SUBJECT_PROFILE_CHANGE, (string) $request->id);
            if (! $instance?->currentStep) {
                return;
            }

            $steps = $instance->template?->steps ?? collect();
            $isLast = $steps->last()?->id === $instance->current_step_id;
            if ($isLast || ! $this->assignees->canSkipIfUnresolved($instance->currentStep)) {
                return;
            }

            $users = $this->assignees->usersForCurrentStep($request, $instance);
            if ($users->isNotEmpty()) {
                return;
            }

            $result = $this->pipelineEngine->transition(
                PipelineEngine::SUBJECT_PROFILE_CHANGE,
                (string) $request->id,
                'approve',
                $actor,
                'Skipped: no assignee for this step.',
            );

            if ($result['completed']) {
                return;
            }
        }
    }

    private function resolvePipeline(?string $pipelineId): PipelineTemplate
    {
        if ($pipelineId) {
            $pipeline = PipelineTemplate::query()
                ->where('id', $pipelineId)
                ->where('subject_type', PipelineEngine::SUBJECT_PROFILE_CHANGE)
                ->where('is_active', true)
                ->first();
            if (! $pipeline) {
                throw ValidationException::withMessages([
                    'pipeline_template_id' => 'Select an active employee profile-change pipeline.',
                ]);
            }

            return $pipeline;
        }

        try {
            return $this->pipelineEngine->resolveTemplate(
                PipelineEngine::SUBJECT_PROFILE_CHANGE,
                'employee_profile_change',
            );
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'pipeline_template_id' => 'No active employee profile-change pipeline is configured.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Employee $employee): array
    {
        $localityName = $employee->locality?->name;

        return [
            'address1' => $this->normalize($employee->address1),
            'address2' => $this->normalize($employee->address2),
            'localityId' => $employee->localityId ? (string) $employee->localityId : null,
            'localityName' => $localityName,
            'phone' => $this->normalize($employee->phone),
            'email' => $this->normalize($employee->email),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function diffProposed(array $payload, array $current): array
    {
        $proposed = [];
        foreach (EmployeeProfileChangeRequest::CHANGEABLE_FIELDS as $field) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }

            $value = $this->normalize($payload[$field]);
            $existing = $this->normalize($current[$field] ?? null);
            if ($value === $existing) {
                continue;
            }

            $proposed[$field] = $value;
            if ($field === 'localityId') {
                $proposed['localityName'] = $value
                    ? Locality::query()->whereKey($value)->value('name')
                    : null;
            }
        }

        return $proposed;
    }

    private function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
