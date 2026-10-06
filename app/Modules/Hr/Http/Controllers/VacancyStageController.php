<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Modules\Hr\Models\VacancyStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class VacancyStageController extends Controller
{
    public function index(): JsonResponse
    {
        $stages = VacancyStage::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (VacancyStage $stage) => $stage->present())
            ->values();

        return response()->json(['data' => $stages]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateStage($request);

        $stage = DB::transaction(function () use ($validated) {
            $maxOrder = (int) VacancyStage::query()->max('sort_order');
            $stage = VacancyStage::create([
                'name' => $validated['name'],
                'color' => $validated['color'] ?? '#64748b',
                'sort_order' => $validated['sort_order'] ?? ($maxOrder + 1),
                'is_default' => (bool) ($validated['is_default'] ?? false),
                'lists_public' => (bool) ($validated['lists_public'] ?? false),
                'is_closed' => (bool) ($validated['is_closed'] ?? false),
            ]);

            if ($stage->is_default) {
                $this->clearOtherDefaults($stage->id);
            }

            $this->ensureDefaultExists();

            return $stage->fresh();
        });

        return response()->json(['data' => $stage->present()], 201);
    }

    public function show(VacancyStage $vacancyStage): JsonResponse
    {
        return response()->json($vacancyStage->present());
    }

    public function update(Request $request, VacancyStage $vacancyStage): JsonResponse
    {
        $validated = $this->validateStage($request, $vacancyStage->id);

        $stage = DB::transaction(function () use ($validated, $vacancyStage) {
            $vacancyStage->update($validated);

            if ($vacancyStage->is_default) {
                $this->clearOtherDefaults($vacancyStage->id);
            }

            $this->ensureDefaultExists();

            return $vacancyStage->fresh();
        });

        return response()->json(['data' => $stage->present()]);
    }

    public function destroy(VacancyStage $vacancyStage): JsonResponse
    {
        if ($vacancyStage->vacancies()->exists()) {
            return response()->json([
                'message' => 'Move or close vacancies in this stage before deleting it.',
            ], 422);
        }

        if (VacancyStage::query()->count() <= 1) {
            return response()->json([
                'message' => 'At least one vacancy stage is required.',
            ], 422);
        }

        DB::transaction(function () use ($vacancyStage) {
            $wasDefault = $vacancyStage->is_default;
            $vacancyStage->delete();

            if ($wasDefault) {
                $this->ensureDefaultExists();
            }
        });

        return response()->json(['message' => 'Vacancy stage deleted.']);
    }

    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct', 'exists:vacancy_stages,id'],
        ]);

        DB::transaction(function () use ($validated) {
            foreach (array_values($validated['ids']) as $index => $id) {
                VacancyStage::query()->whereKey($id)->update(['sort_order' => $index + 1]);
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
                Rule::unique('vacancy_stages', 'name')->ignore($id),
            ],
            'color' => ['nullable', 'string', 'max:32'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_default' => ['sometimes', 'boolean'],
            'lists_public' => ['sometimes', 'boolean'],
            'is_closed' => ['sometimes', 'boolean'],
        ]);
    }

    private function clearOtherDefaults(int $keepId): void
    {
        VacancyStage::query()->where('id', '!=', $keepId)->update(['is_default' => false]);
    }

    private function ensureDefaultExists(): void
    {
        if (VacancyStage::query()->where('is_default', true)->exists()) {
            return;
        }

        $first = VacancyStage::query()->orderBy('sort_order')->orderBy('id')->first();
        $first?->update(['is_default' => true]);
    }
}
