<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Modules\Hr\Models\Applicant;
use App\Modules\Hr\Models\CandidateStage;
use App\Modules\Hr\Models\Vacancy;
use App\Modules\Hr\Models\VacancyApplication;
use App\Modules\Hr\Services\ApplicantToEmployeeConverter;
use App\Support\ConfiguredStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VacancyApplicationController extends Controller
{
    public function __construct(
        private readonly ApplicantToEmployeeConverter $converter,
    ) {
    }

    public function board(Request $request): JsonResponse
    {
        $stages = CandidateStage::query()
            ->with(['applications' => fn ($query) => $query
                ->with(VacancyApplication::defaultRelations())
                ->applyFilters($request)
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function (CandidateStage $stage) {
                return array_merge($stage->present(), [
                    'applications' => $stage->applications
                        ->map(fn (VacancyApplication $application) => $application->present())
                        ->values(),
                ]);
            })
            ->values();

        return response()->json(['data' => $stages]);
    }

    public function publicStore(Request $request, Vacancy $vacancy): JsonResponse
    {
        $vacancy->load('stage');
        if (! $vacancy->isListedPublicly()) {
            return response()->json(['message' => 'This job is not accepting public applications.'], 404);
        }

        return $this->storeApplication($request, $vacancy, VacancyApplication::SOURCE_PUBLIC);
    }

    public function index(Vacancy $vacancy): JsonResponse
    {
        $applications = VacancyApplication::query()
            ->with(VacancyApplication::defaultRelations())
            ->where('vacancy_id', $vacancy->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (VacancyApplication $application) => $application->present())
            ->values();

        return response()->json(['data' => $applications]);
    }

    public function store(Request $request, Vacancy $vacancy): JsonResponse
    {
        return $this->storeApplication($request, $vacancy, VacancyApplication::SOURCE_MANUAL);
    }

    public function show(Vacancy $vacancy, VacancyApplication $application): JsonResponse
    {
        $this->assertApplicationOnVacancy($vacancy, $application);

        return response()->json(
            $application->load(VacancyApplication::defaultRelations())->present(),
        );
    }

    public function convert(
        Request $request,
        Vacancy $vacancy,
        VacancyApplication $application,
    ): JsonResponse {
        $this->assertApplicationOnVacancy($vacancy, $application);

        $validated = $request->validate([
            'first_name' => ['nullable', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address1' => ['nullable', 'string', 'max:255'],
            'address2' => ['nullable', 'string', 'max:255'],
            'locality_id' => ['nullable', 'uuid', 'exists:locality,id'],
            'birthdate' => ['nullable', 'date'],
            'gender_id' => ['nullable', 'integer', 'exists:gender,id'],
            'social_security_number' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'paymentMethodId' => ['nullable', 'integer', 'exists:payment_method,id'],
        ]);

        try {
            $result = $this->converter->convert($application, $validated, $request->user());
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => 'Additional employee details are required.',
                'errors' => $exception->errors(),
            ], 422);
        }

        $employee = $result['employee'];
        $person = $employee->person;

        return response()->json([
            'action' => $result['action'],
            'application' => $result['application']->present(),
            'employee' => [
                'id' => $employee->id,
                'code' => $employee->code,
                'firstName' => $person?->firstName,
                'lastName' => $person?->lastName,
                'email' => $person?->email,
            ],
        ]);
    }

    private function storeApplication(Request $request, Vacancy $vacancy, string $source): JsonResponse
    {
        $resumeRules = $vacancy->require_resume
            ? ['required', 'file', 'mimes:pdf,doc,docx', 'max:10240']
            : ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:10240'];

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address1' => ['nullable', 'string', 'max:255'],
            'address2' => ['nullable', 'string', 'max:255'],
            'locality_id' => ['nullable', 'uuid', 'exists:locality,id'],
            'birthdate' => ['nullable', 'date'],
            'gender_id' => ['nullable', 'integer', 'exists:gender,id'],
            'social_security_number' => ['nullable', 'string', 'max:255'],
            'cover_letter' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'resume' => $resumeRules,
        ]);

        $application = DB::transaction(function () use ($validated, $vacancy, $source, $request) {
            $applicant = Applicant::upsertFromApplication($validated);
            $existing = VacancyApplication::query()
                ->where('vacancy_id', $vacancy->id)
                ->where('applicant_id', $applicant->id)
                ->first();

            $resume = $this->storeResume($request->file('resume'), $existing?->resume_path);

            $payload = [
                'cover_letter' => $validated['cover_letter'] ?? $existing?->cover_letter,
                'source' => $source,
            ];
            if ($resume !== null) {
                $payload['resume_path'] = $resume['path'];
                $payload['resume_name'] = $resume['name'];
            }

            if ($existing) {
                $existing->update($payload);

                return $existing->fresh(VacancyApplication::defaultRelations());
            }

            $stageId = CandidateStage::defaultStage()?->id;
            if (! $stageId) {
                throw ValidationException::withMessages([
                    'candidate_stage_id' => 'Create a candidate stage before accepting applications.',
                ]);
            }

            $sortOrder = (int) VacancyApplication::query()
                ->where('candidate_stage_id', $stageId)
                ->max('sort_order');

            return VacancyApplication::query()->create([
                'vacancy_id' => $vacancy->id,
                'applicant_id' => $applicant->id,
                'candidate_stage_id' => $stageId,
                'cover_letter' => $payload['cover_letter'] ?? null,
                'resume_path' => $payload['resume_path'] ?? null,
                'resume_name' => $payload['resume_name'] ?? null,
                'source' => $source,
                'status' => VacancyApplication::STATUS_RECEIVED,
                'sort_order' => $sortOrder + 1,
            ])->load(VacancyApplication::defaultRelations());
        });

        return response()->json(['data' => $application->present()], 201);
    }

    public function move(Request $request, VacancyApplication $application): JsonResponse
    {
        $validated = $request->validate([
            'candidate_stage_id' => ['required', 'integer', 'exists:candidate_stages,id'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'ordered_ids' => ['sometimes', 'array'],
            'ordered_ids.*' => ['uuid'],
        ]);

        $stageId = (int) $validated['candidate_stage_id'];

        DB::transaction(function () use ($application, $stageId, $validated) {
            $application->update([
                'candidate_stage_id' => $stageId,
                'sort_order' => $validated['sort_order'] ?? $application->sort_order,
            ]);

            $orderedIds = $validated['ordered_ids'] ?? null;
            if (is_array($orderedIds) && $orderedIds !== []) {
                if (! in_array($application->id, $orderedIds, true)) {
                    $orderedIds[] = $application->id;
                }

                foreach (array_values($orderedIds) as $index => $id) {
                    VacancyApplication::query()
                        ->whereKey($id)
                        ->update([
                            'candidate_stage_id' => $stageId,
                            'sort_order' => $index + 1,
                        ]);
                }

                return;
            }

            $this->reindexStage($stageId);
        });

        return response()->json([
            'data' => $application->fresh(VacancyApplication::defaultRelations())->present(),
        ]);
    }

    /**
     * @return array{path: string, name: string}|null
     */
    private function storeResume(mixed $file, ?string $existingPath): ?array
    {
        if (! $file instanceof UploadedFile) {
            return null;
        }

        if ($existingPath) {
            app(ConfiguredStorage::class)->delete($existingPath);
        }

        $extension = $file->getClientOriginalExtension() ?: 'pdf';
        $storedName = 'resume_'.Str::uuid().'.'.$extension;

        return [
            'path' => app(ConfiguredStorage::class)->store($file, 'applicant-resumes', $storedName),
            'name' => $file->getClientOriginalName(),
        ];
    }

    private function assertApplicationOnVacancy(Vacancy $vacancy, VacancyApplication $application): void
    {
        if ($application->vacancy_id !== $vacancy->id) {
            abort(404);
        }
    }

    private function reindexStage(int $stageId): void
    {
        $ids = VacancyApplication::query()
            ->where('candidate_stage_id', $stageId)
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->pluck('id');

        foreach ($ids as $index => $id) {
            VacancyApplication::query()->whereKey($id)->update(['sort_order' => $index + 1]);
        }
    }
}
