<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Models\JobTitle;
use App\Modules\Hr\Models\Vacancy;
use App\Modules\Hr\Models\VacancyStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VacancyController extends Controller
{
    public function board(Request $request): JsonResponse
    {
        $stages = VacancyStage::query()
            ->with(['vacancies' => fn ($query) => $query
                ->with(Vacancy::defaultRelations())
                ->withCount('applications')
                ->applyFilters($request)
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function (VacancyStage $stage) {
                return array_merge($stage->present(), [
                    'vacancies' => $stage->vacancies
                        ->map(fn (Vacancy $vacancy) => $vacancy->present())
                        ->values(),
                ]);
            })
            ->values();

        return response()->json(['data' => $stages]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Vacancy::query()->with(Vacancy::defaultRelations())->withCount('applications');
        $query->applyFilters($request);
        $query->orderBy('sort_order')->orderByDesc('created_at');

        return response()->json($query->paginate((int) $request->get('per_page', 50)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateVacancy($request);
        $stageId = $validated['vacancy_stage_id'] ?? VacancyStage::defaultStage()?->id;

        if (! $stageId) {
            return response()->json(['message' => 'Create a vacancy stage before posting a vacancy.'], 422);
        }

        $vacancy = DB::transaction(function () use ($validated, $stageId) {
            $sortOrder = (int) Vacancy::query()->where('vacancy_stage_id', $stageId)->max('sort_order');

            $vacancy = Vacancy::create([
                ...$this->vacancyAttributes($validated),
                'description' => $this->resolveDescription($validated),
                'vacancy_stage_id' => $stageId,
                'sort_order' => $sortOrder + 1,
            ]);

            return $vacancy->load(Vacancy::defaultRelations());
        });

        return response()->json(['data' => $vacancy->present()], 201);
    }

    public function show(Vacancy $vacancy): JsonResponse
    {
        return response()->json($vacancy->load(Vacancy::defaultRelations())->present());
    }

    public function update(Request $request, Vacancy $vacancy): JsonResponse
    {
        $validated = $this->validateVacancy($request, updating: true);
        $attributes = $this->vacancyAttributes($validated);

        if (array_key_exists('description', $validated) || array_key_exists('job_title_id', $validated)) {
            $attributes['description'] = $this->resolveDescription(
                array_merge($vacancy->only(['job_title_id', 'description']), $validated),
            );
        }

        $targetStageId = $attributes['vacancy_stage_id'] ?? $vacancy->vacancy_stage_id;

        DB::transaction(function () use ($vacancy, $attributes, $targetStageId) {
            if ($targetStageId !== $vacancy->vacancy_stage_id) {
                $sortOrder = (int) Vacancy::query()->where('vacancy_stage_id', $targetStageId)->max('sort_order');
                $attributes['sort_order'] = $sortOrder + 1;
            }

            $vacancy->update($attributes);
        });

        return response()->json([
            'data' => $vacancy->fresh(Vacancy::defaultRelations())->present(),
        ]);
    }

    public function destroy(Vacancy $vacancy): JsonResponse
    {
        $vacancy->delete();

        return response()->json(['message' => 'Vacancy deleted.']);
    }

    public function move(Request $request, Vacancy $vacancy): JsonResponse
    {
        $validated = $request->validate([
            'vacancy_stage_id' => ['required', 'integer', 'exists:vacancy_stages,id'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'ordered_ids' => ['sometimes', 'array'],
            'ordered_ids.*' => ['uuid'],
        ]);

        $stageId = (int) $validated['vacancy_stage_id'];

        DB::transaction(function () use ($vacancy, $stageId, $validated) {
            $vacancy->update([
                'vacancy_stage_id' => $stageId,
                'sort_order' => $validated['sort_order'] ?? $vacancy->sort_order,
            ]);

            $orderedIds = $validated['ordered_ids'] ?? null;
            if (is_array($orderedIds) && $orderedIds !== []) {
                if (! in_array($vacancy->id, $orderedIds, true)) {
                    $orderedIds[] = $vacancy->id;
                }

                foreach (array_values($orderedIds) as $index => $id) {
                    Vacancy::query()
                        ->whereKey($id)
                        ->update([
                            'vacancy_stage_id' => $stageId,
                            'sort_order' => $index + 1,
                        ]);
                }

                return;
            }

            $this->reindexStage($stageId);
        });

        return response()->json([
            'data' => $vacancy->fresh(Vacancy::defaultRelations())->present(),
        ]);
    }

    public function publicIndex(): JsonResponse
    {
        $vacancies = Vacancy::query()
            ->with(Vacancy::defaultRelations())
            ->where('advertise_public', true)
            ->whereHas('stage', function ($query) {
                $query->where('lists_public', true)->where('is_closed', false);
            })
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Vacancy $vacancy) => $vacancy->present(public: true))
            ->values();

        return response()->json(['data' => $vacancies]);
    }

    public function publicShow(Vacancy $vacancy): JsonResponse
    {
        $vacancy->load(Vacancy::defaultRelations());

        if (! $vacancy->isListedPublicly()) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json($vacancy->present(public: true));
    }

    /**
     * @return array<string, mixed>
     */
    private function validateVacancy(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'max:255'],
            'job_title_id' => ['nullable', 'integer', 'exists:job_title,id'],
            'worksite_id' => ['nullable', 'integer', 'exists:worksite,id'],
            'department_id' => ['nullable', 'integer', 'exists:department,id'],
            'hiring_manager_id' => ['nullable', 'uuid', 'exists:employee,id'],
            'positions' => ['nullable', 'integer', 'min:1', 'max:999'],
            'require_resume' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string'],
            'advertise_internal' => ['sometimes', 'boolean'],
            'advertise_public' => ['sometimes', 'boolean'],
            'vacancy_stage_id' => ['nullable', 'integer', 'exists:vacancy_stages,id'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function vacancyAttributes(array $validated): array
    {
        $attributes = [];

        foreach ([
            'title',
            'job_title_id',
            'worksite_id',
            'department_id',
            'hiring_manager_id',
            'positions',
            'require_resume',
            'advertise_internal',
            'advertise_public',
            'vacancy_stage_id',
        ] as $field) {
            if (array_key_exists($field, $validated)) {
                $attributes[$field] = $validated[$field];
            }
        }

        if (array_key_exists('positions', $attributes) && $attributes['positions'] === null) {
            $attributes['positions'] = 1;
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveDescription(array $validated): ?string
    {
        if (array_key_exists('description', $validated) && filled($validated['description'])) {
            return $validated['description'];
        }

        $jobTitleId = $validated['job_title_id'] ?? null;
        if (! $jobTitleId) {
            return $validated['description'] ?? null;
        }

        $notes = JobTitle::query()->whereKey($jobTitleId)->value('notes');

        return filled($notes) ? (string) $notes : ($validated['description'] ?? null);
    }

    private function reindexStage(int $stageId): void
    {
        $ids = Vacancy::query()
            ->where('vacancy_stage_id', $stageId)
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->pluck('id');

        foreach ($ids as $index => $id) {
            Vacancy::query()->whereKey($id)->update(['sort_order' => $index + 1]);
        }
    }
}
