<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeProfileChangeRequest;
use App\Modules\Hr\Services\ProfileChange\ProfileChangeWorkflowService;
use App\Modules\Workflow\Services\PipelineEngine;
use App\Support\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class EmployeeProfileChangeController extends Controller
{
    public function __construct(
        private readonly ProfileChangeWorkflowService $workflow,
        private readonly PipelineEngine $pipelineEngine,
    ) {}

    public function inbox(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = $this->workflow->inboxFor($user);

        return response()->json([
            'data' => collect($rows)
                ->map(fn (EmployeeProfileChangeRequest $row) => $row->present($this->pipelineEngine, $this->workflow->flags($row, $user)))
                ->values(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless(Access::canViewEmployeeDirectory($request->user()), 403);

        $query = EmployeeProfileChangeRequest::query()
            ->with(['employee.person.locality'])
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
                fn (EmployeeProfileChangeRequest $row) => $row->present($this->pipelineEngine, $this->workflow->flags($row, $user))
            )->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = Employee::query()
            ->where('user_id', $user?->id)
            ->first();

        if (! $employee) {
            return response()->json(['message' => 'No employee record is linked to your account.'], 404);
        }

        $validated = $request->validate([
            'address1' => ['nullable', 'string', 'max:255'],
            'address2' => ['nullable', 'string', 'max:255'],
            'localityId' => ['nullable', 'uuid', 'exists:locality,id'],
            'phone' => ['nullable', 'string', 'max:12'],
            'email' => ['nullable', 'email', 'max:255'],
            'pipeline_template_id' => ['nullable', 'uuid', 'exists:pipeline_templates,id'],
        ]);

        $row = $this->workflow->submit($employee, $user, $validated);

        return response()->json([
            'message' => 'Profile change submitted for approval.',
            'data' => $row->present($this->pipelineEngine, $this->workflow->flags($row, $user)),
        ], Response::HTTP_CREATED);
    }

    public function show(Request $request, EmployeeProfileChangeRequest $profileChange): JsonResponse
    {
        abort_unless($this->workflow->canView($request->user(), $profileChange), 403);

        return response()->json([
            'data' => $profileChange->present($this->pipelineEngine, $this->workflow->flags($profileChange, $request->user())),
        ]);
    }

    public function act(Request $request, EmployeeProfileChangeRequest $profileChange): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(['approve', 'reject', 'return', 'cancel'])],
            'note' => ['nullable', 'string', 'max:4000'],
        ]);

        $row = $this->workflow->act($profileChange, $request->user(), $validated);

        return response()->json([
            'message' => 'Profile change updated.',
            'data' => $row->present($this->pipelineEngine, $this->workflow->flags($row, $request->user())),
        ]);
    }
}
