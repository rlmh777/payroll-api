<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Modules\Hr\Models\CandidateStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CandidateStageController extends Controller
{
    public function index(): JsonResponse
    {
        $stages = CandidateStage::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (CandidateStage $stage) => $stage->present())
            ->values();

        return response()->json(['data' => $stages]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateStage($request);

        $stage = DB::transaction(function () use ($validated) {
            $maxOrder = (int) CandidateStage::query()->max('sort_order');
            $stage = CandidateStage::create([
                'name' => $validated['name'],
                'color' => $validated['color'] ?? '#64748b',
                'sort_order' => $validated['sort_order'] ?? ($maxOrder + 1),
                'is_default' => (bool) ($validated['is_default'] ?? false),
                'is_hired' => (bool) ($validated['is_hired'] ?? false),
                'is_rejected' => (bool) ($validated['is_rejected'] ?? false),
            ]);

            $this->syncFlags($stage);

            return $stage->fresh();
        });

        return response()->json(['data' => $stage->present()], 201);
    }

    public function show(CandidateStage $candidateStage): JsonResponse
    {
        return response()->json($candidateStage->present());
    }

    public function update(Request $request, CandidateStage $candidateStage): JsonResponse
    {
        $validated = $this->validateStage($request, $candidateStage->id);

        $stage = DB::transaction(function () use ($validated, $candidateStage) {
            $candidateStage->update($validated);
            $this->syncFlags($candidateStage);

            return $candidateStage->fresh();
        });

        return response()->json(['data' => $stage->present()]);
    }

    public function destroy(CandidateStage $candidateStage): JsonResponse
    {
        if ($candidateStage->applications()->exists()) {
            return response()->json([
                'message' => 'Move candidates out of this stage before deleting it.',
            ], 422);
        }

        if (CandidateStage::query()->count() <= 1) {
            return response()->json([
                'message' => 'At least one candidate stage is required.',
            ], 422);
        }

        DB::transaction(function () use ($candidateStage) {
            $wasDefault = $candidateStage->is_default;
            $candidateStage->delete();

            if ($wasDefault) {
                $this->ensureDefaultExists();
            }
        });

        return response()->json(['message' => 'Candidate stage deleted.']);
    }

    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct', 'exists:candidate_stages,id'],
        ]);

        DB::transaction(function () use ($validated) {
            foreach (array_values($validated['ids']) as $index => $id) {
                CandidateStage::query()->whereKey($id)->update(['sort_order' => $index + 1]);
            }
        });

        return $this->index();
    }

    /**
     * @return array<string, mixed>
     */
    private function validateStage(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('candidate_stages', 'name')->ignore($id),
            ],
            'color' => ['nullable', 'string', 'max:32'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_default' => ['sometimes', 'boolean'],
            'is_hired' => ['sometimes', 'boolean'],
            'is_rejected' => ['sometimes', 'boolean'],
        ]);
    }

    private function syncFlags(CandidateStage $stage): void
    {
        if ($stage->is_default) {
            CandidateStage::query()->where('id', '!=', $stage->id)->update(['is_default' => false]);
        }
        if ($stage->is_hired) {
            CandidateStage::query()->where('id', '!=', $stage->id)->update(['is_hired' => false]);
        }
        if ($stage->is_rejected) {
            CandidateStage::query()->where('id', '!=', $stage->id)->update(['is_rejected' => false]);
        }

        $this->ensureDefaultExists();
    }

    private function ensureDefaultExists(): void
    {
        if (CandidateStage::query()->where('is_default', true)->exists()) {
            return;
        }

        $first = CandidateStage::query()->orderBy('sort_order')->orderBy('id')->first();
        $first?->update(['is_default' => true]);
    }
}
