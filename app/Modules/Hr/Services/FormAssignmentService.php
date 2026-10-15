<?php

namespace App\Modules\Hr\Services;

use App\Models\Employee;
use App\Models\EmploymentDetail;
use App\Models\PipelineTemplate;
use App\Models\User;
use App\Modules\Hr\Models\FormAssignment;
use App\Modules\Hr\Models\FormResponse;
use App\Modules\Hr\Models\FormTemplate;
use App\Modules\Hr\Support\FormSchema;
use App\Modules\Workflow\Services\PipelineEngine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class FormAssignmentService
{
    public function __construct(
        private readonly PipelineEngine $pipelineEngine,
    ) {}

    /**
     * @return Collection<int, FormAssignment>
     */
    public function pendingFor(User $user, bool $requiredOnLoginOnly = false): Collection
    {
        $employee = $this->employeeFor($user);
        if (! $employee) {
            return collect();
        }

        $submittedIds = FormResponse::query()
            ->where('user_id', $user->id)
            ->whereNotNull('form_assignment_id')
            ->whereNotNull('submitted_at')
            ->pluck('form_assignment_id');

        return FormAssignment::query()
            ->with(['template', 'departments:id', 'employeeGroups:id', 'employees:id', 'pipelineTemplate'])
            ->where('is_active', true)
            ->whereHas('template', fn ($query) => $query->where('is_active', true))
            ->when($requiredOnLoginOnly, fn ($query) => $query->where('show_on_login', true)->where('is_required', true))
            ->where(function ($query) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->when($submittedIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $submittedIds))
            ->orderByDesc('is_required')
            ->orderBy('starts_at')
            ->get()
            ->filter(fn (FormAssignment $assignment) => $this->appliesTo($assignment, $employee))
            ->values();
    }

    public function appliesTo(FormAssignment $assignment, Employee $employee): bool
    {
        return match ($assignment->audience_type) {
            FormAssignment::AUDIENCE_COMPANY => true,
            FormAssignment::AUDIENCE_DEPARTMENTS => $this->inDepartments($assignment, $employee),
            FormAssignment::AUDIENCE_EMPLOYEE_GROUPS => $this->inGroups($assignment, $employee),
            FormAssignment::AUDIENCE_EMPLOYEES => $assignment->employees->contains(
                fn ($assigned) => (string) $assigned->id === (string) $employee->id,
            ),
            default => false,
        };
    }

    public function assertAccessible(FormAssignment $assignment, User $user, bool $forSubmit = false): void
    {
        $employee = $this->employeeFor($user);
        if (! $employee) {
            abort(response()->json(['message' => 'This form is not available.'], 404));
        }

        if (! $this->appliesTo($assignment, $employee)) {
            abort(response()->json(['message' => 'This form is not assigned to you.'], 403));
        }

        $existing = $this->responseFor($assignment, $user);
        if (! $forSubmit && $existing?->submitted_at) {
            return;
        }

        if (! $assignment->is_active || ! $assignment->template?->is_active || ! $assignment->isOpen()) {
            abort(response()->json(['message' => 'This form is not available.'], 404));
        }
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    public function saveResponse(
        FormAssignment $assignment,
        User $user,
        array $answers,
        ?string $comments = null,
        bool $submit = true,
    ): FormResponse {
        $this->assertAccessible($assignment, $user, true);
        $template = $assignment->template;
        if (! $template instanceof FormTemplate) {
            abort(response()->json(['message' => 'This form is not available.'], 404));
        }

        $schema = $template->normalizedSchema();
        $clean = FormSchema::validateAnswers($schema, $answers);
        $score = FormSchema::score($schema, $clean);

        $response = DB::transaction(function () use ($assignment, $template, $user, $schema, $clean, $comments, $submit, $score) {
            $record = FormResponse::query()->firstOrNew([
                'form_assignment_id' => $assignment->id,
                'user_id' => $user->id,
            ]);

            if ($record->submitted_at) {
                throw ValidationException::withMessages([
                    'answers' => 'This form has already been submitted.',
                ]);
            }

            $record->fill([
                'form_template_id' => $template->id,
                'schema_snapshot' => $schema,
                'answers' => $clean,
                'comments' => filled($comments) ? $comments : null,
                'total_score' => $score['total'],
                'max_score' => $score['max'],
                'submitted_at' => $submit ? now() : null,
            ]);
            $record->save();

            if ($submit) {
                $this->startPipeline($assignment, $record, $user);
            }

            return $record->load(['assignment', 'user']);
        });

        return $response;
    }

    public function responseFor(FormAssignment $assignment, User $user): ?FormResponse
    {
        return FormResponse::query()
            ->where('form_assignment_id', $assignment->id)
            ->where('user_id', $user->id)
            ->first();
    }

    private function startPipeline(FormAssignment $assignment, FormResponse $response, User $user): void
    {
        if (! $assignment->pipeline_template_id) {
            return;
        }

        $template = PipelineTemplate::query()->find($assignment->pipeline_template_id);
        if (! $template || ! $template->is_active) {
            throw ValidationException::withMessages([
                'pipeline_template_id' => 'The assigned review pipeline is missing or inactive.',
            ]);
        }

        try {
            $this->pipelineEngine->start(
                PipelineEngine::SUBJECT_FORM_RESPONSE,
                (string) $response->id,
                $user,
                $template->key,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'pipeline_template_id' => $exception->getMessage(),
            ]);
        }
    }

    private function employeeFor(User $user): ?Employee
    {
        return Employee::query()
            ->with(['employmentDetails', 'groupMemberships'])
            ->where('user_id', $user->id)
            ->first();
    }

    private function inDepartments(FormAssignment $assignment, Employee $employee): bool
    {
        $departmentId = $this->activeDepartmentId($employee);
        if ($departmentId === null) {
            return false;
        }

        return $assignment->departments->contains(
            fn ($department) => (int) $department->id === $departmentId,
        );
    }

    private function inGroups(FormAssignment $assignment, Employee $employee): bool
    {
        $groupIds = $assignment->employeeGroups->pluck('id')->map(fn ($id) => (string) $id);
        if ($groupIds->isEmpty()) {
            return false;
        }

        return $employee->groupMemberships
            ->filter(function ($membership) {
                $today = now()->toDateString();
                $started = $membership->startDate === null || $membership->startDate->toDateString() <= $today;
                $ended = $membership->endDate === null || $membership->endDate->toDateString() >= $today;

                return $started && $ended;
            })
            ->contains(fn ($membership) => $groupIds->contains((string) $membership->employeeGroupId));
    }

    private function activeDepartmentId(Employee $employee): ?int
    {
        $detail = $employee->employmentDetails->first(fn (EmploymentDetail $row) => (bool) $row->isActive)
            ?? $employee->employmentDetails->sortByDesc('startDate')->first();

        return $detail?->departmentId ? (int) $detail->departmentId : null;
    }
}
