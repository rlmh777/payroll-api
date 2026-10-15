<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Modules\Hr\Models\FormAssignment;
use App\Modules\Hr\Services\FormAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyFormsController extends Controller
{
    public function __construct(
        private readonly FormAssignmentService $assignments,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $requiredOnly = $request->boolean('required_on_login');
        $pending = $this->assignments->pendingFor($user, $requiredOnly);

        return response()->json([
            'data' => $pending->map(function (FormAssignment $assignment) use ($user) {
                $response = $this->assignments->responseFor($assignment, $user);

                return [
                    ...$assignment->present(true),
                    'is_submitted' => $response?->submitted_at !== null,
                    'has_draft' => $response !== null && $response->submitted_at === null,
                ];
            })->values(),
        ]);
    }

    public function show(Request $request, FormAssignment $formAssignment): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $formAssignment->load(['template', 'departments:id,name', 'employeeGroups:id,name', 'employees.person', 'pipelineTemplate']);
        $this->assignments->assertAccessible($formAssignment, $user);
        $response = $this->assignments->responseFor($formAssignment, $user);

        return response()->json([
            'data' => [
                ...$formAssignment->present(true),
                'response' => $response?->present(),
            ],
        ]);
    }

    public function save(Request $request, FormAssignment $formAssignment): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $formAssignment->load(['template', 'departments:id', 'employeeGroups:id', 'employees:id']);
        $validated = $request->validate([
            'answers' => ['required', 'array'],
            'comments' => ['nullable', 'string'],
            'submit' => ['sometimes', 'boolean'],
        ]);

        $response = $this->assignments->saveResponse(
            $formAssignment,
            $user,
            $validated['answers'],
            $validated['comments'] ?? null,
            (bool) ($validated['submit'] ?? true),
        );

        return response()->json([
            'message' => $response->submitted_at ? 'Form submitted.' : 'Draft saved.',
            'data' => $response->present(),
        ]);
    }
}
