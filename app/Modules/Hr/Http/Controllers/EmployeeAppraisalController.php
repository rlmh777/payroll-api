<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\EmploymentDetail;
use App\Models\PipelineTemplate;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeAppraisal;
use App\Modules\Hr\Models\FormTemplate;
use App\Modules\Hr\Services\Appraisal\AppraisalWorkflowService;
use App\Modules\Hr\Services\Leave\LeaveSupervisorAuthorizationService;
use App\Modules\Workflow\Services\PipelineEngine;
use App\Support\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class EmployeeAppraisalController extends Controller
{
    public function __construct(
        private readonly AppraisalWorkflowService $workflow,
        private readonly PipelineEngine $pipelineEngine,
        private readonly LeaveSupervisorAuthorizationService $supervisors,
    ) {}

    public function options(): JsonResponse
    {
        return response()->json([
            'form_templates' => FormTemplate::query()
                ->where('purpose', FormTemplate::PURPOSE_APPRAISAL)
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (FormTemplate $template) => $template->summary())
                ->values(),
            'pipeline_templates' => PipelineTemplate::query()
                ->where('subject_type', PipelineEngine::SUBJECT_APPRAISAL)
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (PipelineTemplate $template) => [
                    'id' => $template->id,
                    'key' => $template->key,
                    'name' => $template->name,
                ])
                ->values(),
        ]);
    }

    public function inbox(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = $this->workflow->inboxFor($user);

        return response()->json([
            'data' => collect($rows)
                ->map(fn (EmployeeAppraisal $row) => $row->present($this->pipelineEngine, $this->workflow->flags($row, $user)))
                ->values(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless(Access::canViewAppraisals($request->user()), 403);

        $query = EmployeeAppraisal::query()
            ->with(['employee.person', 'formTemplate', 'formResponse'])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->string('employee_id'));
        }

        $user = $request->user();

        return response()->json([
            'data' => $query->limit(200)->get()->map(
                fn (EmployeeAppraisal $row) => $row->present($this->pipelineEngine, $this->workflow->flags($row, $user))
            )->values(),
        ]);
    }

    public function candidates(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Employee::query()->with(['person']);
        if (! Access::canManageAppraisals($user)) {
            $ids = $this->supervisors->subordinateEmployeeIds($user);
            $query->whereIn('id', $ids->all());
        } else {
            $query->whereHas('employmentDetails', fn ($employment) => $employment->where('isActive', true));
        }

        $rows = $query->orderBy('id')->limit(200)->get();

        return response()->json([
            'data' => $rows->map(fn (Employee $employee) => $this->candidatePayload($employee))->values(),
        ]);
    }

    public function contract(Request $request, Employee $employee): JsonResponse
    {
        abort_unless($this->workflow->canStartFor($request->user(), (string) $employee->id), 403);

        return response()->json([
            'data' => $this->candidatePayload($employee),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'uuid', 'exists:employee,id'],
            'form_template_id' => ['nullable', 'uuid', 'exists:form_templates,id'],
            'pipeline_template_id' => ['nullable', 'uuid', 'exists:pipeline_templates,id'],
            'period_label' => ['required', 'string', 'max:255'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
        ]);

        $appraisal = $this->workflow->start($validated, $request->user());

        return response()->json([
            'message' => 'Appraisal started.',
            'data' => $appraisal->present($this->pipelineEngine, $this->workflow->flags($appraisal, $request->user())),
        ], Response::HTTP_CREATED);
    }

    public function show(Request $request, EmployeeAppraisal $appraisal): JsonResponse
    {
        abort_unless($this->workflow->canView($request->user(), $appraisal), 403);

        return response()->json([
            'data' => $appraisal->present($this->pipelineEngine, $this->workflow->flags($appraisal, $request->user())),
        ]);
    }

    public function saveForm(Request $request, EmployeeAppraisal $appraisal): JsonResponse
    {
        $validated = $request->validate([
            'answers' => ['required', 'array'],
            'comments' => ['nullable', 'string'],
            'submit' => ['sometimes', 'boolean'],
        ]);

        $this->workflow->saveForm($appraisal, $request->user(), $validated, (bool) ($validated['submit'] ?? false));
        $appraisal->refresh();

        return response()->json([
            'message' => ($validated['submit'] ?? false) ? 'Appraisal form submitted.' : 'Appraisal form saved.',
            'data' => $appraisal->present($this->pipelineEngine, $this->workflow->flags($appraisal, $request->user())),
        ]);
    }

    public function act(Request $request, EmployeeAppraisal $appraisal): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(['approve', 'reject', 'return', 'cancel'])],
            'note' => ['nullable', 'string', 'max:4000'],
            'hr_renew_contract' => ['sometimes', 'boolean'],
            'next_appraisal_date' => ['nullable', 'date'],
            'hr_increment_approved' => ['sometimes', 'boolean'],
            'hr_increment_type' => ['nullable', 'string', Rule::in(['percent', 'amount'])],
            'hr_increment_value' => ['nullable', 'numeric', 'min:0'],
            'hr_notes' => ['nullable', 'string', 'max:4000'],
            'accounts_notes' => ['nullable', 'string', 'max:4000'],
            'answers' => ['sometimes', 'array'],
            'submit_form' => ['sometimes', 'boolean'],
        ]);

        if (($validated['submit_form'] ?? false) || isset($validated['answers'])) {
            $this->workflow->saveForm($appraisal, $request->user(), $validated, (bool) ($validated['submit_form'] ?? true));
        }

        $appraisal = $this->workflow->act($appraisal->fresh(), $request->user(), $validated);

        return response()->json([
            'message' => 'Appraisal updated.',
            'data' => $appraisal->present($this->pipelineEngine, $this->workflow->flags($appraisal, $request->user())),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function candidatePayload(Employee $employee): array
    {
        $contract = EmploymentDetail::query()
            ->with('appraisalForm')
            ->where('employeeId', $employee->id)
            ->where('isActive', true)
            ->orderByDesc('startDate')
            ->first();
        $form = $contract?->appraisalForm;
        $usableForm = $form && $form->is_active && $form->purpose === FormTemplate::PURPOSE_APPRAISAL
            ? $form
            : null;

        $name = trim((string) $employee->firstName.' '.(string) $employee->lastName);

        return [
            'id' => $employee->id,
            'code' => $employee->code,
            'name' => $name !== '' ? $name : (string) ($employee->code ?: 'Employee'),
            'appraisal_form' => $usableForm ? [
                'id' => $usableForm->id,
                'name' => $usableForm->name,
            ] : null,
            'appraisal_date' => $contract?->appraisalDate?->toDateString(),
            'contract_end_date' => $contract?->endDate?->toDateString(),
        ];
    }
}
