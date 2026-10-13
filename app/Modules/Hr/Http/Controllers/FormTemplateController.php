<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Modules\Hr\Models\FormTemplate;
use App\Modules\Hr\Support\FormSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class FormTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = FormTemplate::query()->orderBy('name');

        if ($request->filled('purpose') && in_array($request->input('purpose'), FormTemplate::purposes(), true)) {
            $query->where('purpose', $request->input('purpose'));
        }

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->string('search'));
            $query->where(function ($inner) use ($search) {
                $inner->where('name', 'ilike', "%{$search}%")
                    ->orWhere('description', 'ilike', "%{$search}%");
            });
        }

        return response()->json([
            'data' => $query->get()->map(fn (FormTemplate $template) => $template->present())->values(),
            'meta' => [
                'purposes' => FormTemplate::purposes(),
                'field_types' => FormSchema::TYPES,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateTemplate($request);
        $template = FormTemplate::query()->create($validated);

        return response()->json([
            'message' => 'Form template created.',
            'data' => $template->present(),
        ], Response::HTTP_CREATED);
    }

    public function show(FormTemplate $formTemplate): JsonResponse
    {
        return response()->json(['data' => $formTemplate->present()]);
    }

    public function update(Request $request, FormTemplate $formTemplate): JsonResponse
    {
        $formTemplate->update($this->validateTemplate($request, $formTemplate));

        return response()->json([
            'message' => 'Form template saved.',
            'data' => $formTemplate->fresh()?->present(),
        ]);
    }

    public function destroy(FormTemplate $formTemplate): JsonResponse
    {
        if ($formTemplate->responses()->exists() || $formTemplate->assignments()->exists()) {
            return response()->json([
                'message' => 'This form has assignments or recorded responses. Disable it instead of deleting.',
            ], 422);
        }

        $formTemplate->vacancies()->update(['form_template_id' => null]);
        $formTemplate->delete();

        return response()->json(['message' => 'Form template deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateTemplate(Request $request, ?FormTemplate $existing = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'purpose' => ['sometimes', 'string', Rule::in(FormTemplate::purposes())],
            'schema' => ['required'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return [
            'name' => trim((string) $validated['name']),
            'description' => filled($validated['description'] ?? null) ? trim((string) $validated['description']) : null,
            'purpose' => $validated['purpose'] ?? $existing?->purpose ?? FormTemplate::PURPOSE_SCORE_SHEET,
            'schema' => FormSchema::normalize($validated['schema']),
            'is_active' => (bool) ($validated['is_active'] ?? $existing?->is_active ?? true),
        ];
    }
}
