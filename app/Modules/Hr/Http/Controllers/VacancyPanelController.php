<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\FormResponse;
use App\Modules\Hr\Models\FormTemplate;
use App\Modules\Hr\Models\Vacancy;
use App\Modules\Hr\Models\VacancyApplication;
use App\Modules\Hr\Models\VacancyPanelMember;
use App\Modules\Hr\Services\PanelInviteService;
use App\Modules\Hr\Support\FormSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class VacancyPanelController extends Controller
{
    public function __construct(
        private readonly PanelInviteService $invites,
    ) {}

    public function index(Vacancy $vacancy): JsonResponse
    {
        $members = $vacancy->panelMembers()
            ->orderBy('name')
            ->get()
            ->map(fn (VacancyPanelMember $member) => $member->present())
            ->values();

        return response()->json([
            'data' => $members,
            'form_template' => $vacancy->formTemplate?->present(),
        ]);
    }

    public function store(Request $request, Vacancy $vacancy): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'member_type' => ['nullable', Rule::in([VacancyPanelMember::TYPE_INTERNAL, VacancyPanelMember::TYPE_EXTERNAL])],
            'employee_id' => ['required_if:member_type,internal', 'nullable', 'uuid', 'exists:employee,id'],
            'name' => ['required_unless:member_type,internal', 'nullable', 'string', 'max:255'],
            'email' => ['required_unless:member_type,internal', 'nullable', 'email', 'max:255'],
        ]);

        $result = $this->invites->invite(
            $vacancy,
            (string) ($validated['name'] ?? ''),
            (string) ($validated['email'] ?? ''),
            true,
            (string) ($validated['member_type'] ?? VacancyPanelMember::TYPE_EXTERNAL),
            isset($validated['employee_id']) ? (string) $validated['employee_id'] : null,
        );

        return response()->json([
            'message' => 'Panel member invited.',
            'data' => $result['member']->present(),
        ], Response::HTTP_CREATED);
    }

    public function resend(Vacancy $vacancy, VacancyPanelMember $panelMember): JsonResponse
    {
        $this->invites->resend($vacancy, $panelMember);

        return response()->json([
            'message' => 'Invitation resent.',
            'data' => $panelMember->fresh()?->present(),
        ]);
    }

    public function destroy(Vacancy $vacancy, VacancyPanelMember $panelMember): JsonResponse
    {
        if ($panelMember->vacancy_id !== $vacancy->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $this->invites->revoke($panelMember);

        return response()->json(['message' => 'Panel member removed.']);
    }

    public function scores(Vacancy $vacancy, VacancyApplication $application): JsonResponse
    {
        if ($application->vacancy_id !== $vacancy->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $template = $vacancy->formTemplate;
        $submitted = FormResponse::query()
            ->with(['panelMember', 'user'])
            ->where('vacancy_application_id', $application->id)
            ->whereNotNull('submitted_at')
            ->orderByDesc('submitted_at')
            ->get();
        $average = $submitted->avg('total_score');
        $max = $template ? FormSchema::score($template->normalizedSchema(), [])['max'] : null;

        return response()->json([
            'form_template' => $template?->present(),
            'summary' => [
                'count' => $submitted->count(),
                'average' => $average !== null ? round((float) $average, 2) : null,
                'total' => round((float) $submitted->sum('total_score'), 2),
                'max_score' => $max,
            ],
            'field_totals' => $this->fieldTotals($submitted, $template),
            'data' => $submitted->map(fn (FormResponse $response) => $response->present())->values(),
        ]);
    }

    public function interview(Vacancy $vacancy): JsonResponse
    {
        $template = $vacancy->formTemplate;
        $applications = $this->shortlistedApplications($vacancy);
        $responses = FormResponse::query()
            ->with(['panelMember', 'user'])
            ->where('vacancy_id', $vacancy->id)
            ->whereNotNull('submitted_at')
            ->whereIn('vacancy_application_id', $applications->pluck('id'))
            ->get()
            ->groupBy('vacancy_application_id');
        $members = $vacancy->panelMembers()
            ->with('recommendedApplication.applicant')
            ->orderBy('name')
            ->get();

        $candidates = $applications->map(function (VacancyApplication $application) use ($responses, $template, $members) {
            /** @var Collection<int, FormResponse> $sheets */
            $sheets = $responses->get($application->id, collect());
            $average = $sheets->avg('total_score');

            return [
                'application' => $application->present(),
                'summary' => [
                    'count' => $sheets->count(),
                    'average' => $average !== null ? round((float) $average, 2) : null,
                    'total' => round((float) $sheets->sum('total_score'), 2),
                    'max_score' => $template ? FormSchema::score($template->normalizedSchema(), [])['max'] : null,
                ],
                'field_totals' => $this->fieldTotals($sheets, $template),
                'recommendations' => $members
                    ->where('status', '!=', VacancyPanelMember::STATUS_REVOKED)
                    ->where('recommended_application_id', $application->id)
                    ->count(),
                'scores' => $sheets->map(fn (FormResponse $response) => $response->present())->values(),
            ];
        })->values();

        return response()->json([
            'form_template' => $template?->present(),
            'panel' => $members->map(fn (VacancyPanelMember $member) => $member->present())->values(),
            'candidates' => $candidates,
        ]);
    }

    public function assignScoreSheet(Request $request, Vacancy $vacancy): JsonResponse
    {
        $validated = $request->validate([
            'form_template_id' => ['required', 'uuid', 'exists:form_templates,id'],
        ]);

        $template = FormTemplate::query()->findOrFail($validated['form_template_id']);
        if ($template->purpose !== FormTemplate::PURPOSE_SCORE_SHEET || ! $template->is_active) {
            return response()->json(['message' => 'Choose an active score sheet.'], 422);
        }

        $vacancy->form_template_id = $template->id;
        $vacancy->save();

        return response()->json([
            'message' => 'Score sheet assigned.',
            'data' => $template->present(),
        ]);
    }

    public function employeeOptions(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $query = Employee::query()->with('person')->orderBy('code');
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($outer) use ($like) {
                $outer->where('code', 'like', $like)
                    ->orWhereHas('person', function ($person) use ($like) {
                        $person->where('firstName', 'like', $like)
                            ->orWhere('lastName', 'like', $like)
                            ->orWhere('email', 'like', $like);
                    });
            });
        }

        $data = $query->limit(20)->get()->map(function (Employee $employee) {
            $name = trim((string) $employee->firstName.' '.(string) $employee->lastName);
            $email = mb_strtolower(trim((string) $employee->email));

            return [
                'id' => $employee->id,
                'name' => $name !== '' ? $name : ($employee->code ?: 'Employee'),
                'email' => $email,
                'code' => $employee->code,
            ];
        })->filter(fn (array $row) => $row['email'] !== '')->values();

        return response()->json(['data' => $data]);
    }

    public function upsertScore(Request $request, Vacancy $vacancy, VacancyApplication $application): JsonResponse
    {
        if ($application->vacancy_id !== $vacancy->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $template = $this->resolveTemplate($vacancy);
        $schema = $template->normalizedSchema();
        $validated = $request->validate([
            'answers' => ['required', 'array'],
            'comments' => ['nullable', 'string'],
            'submit' => ['sometimes', 'boolean'],
        ]);

        $answers = FormSchema::validateAnswers($schema, $validated['answers']);
        $score = FormSchema::score($schema, $answers);
        $this->assertShortlisted($application);
        $member = VacancyPanelMember::query()
            ->where('vacancy_id', $vacancy->id)
            ->where(function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->orWhereRaw('LOWER(email) = ?', [mb_strtolower((string) $user->email)]);
            })
            ->where('status', '!=', VacancyPanelMember::STATUS_REVOKED)
            ->first();
        if (! $member) {
            abort(response()->json(['message' => 'You are not on this interview panel.'], 403));
        }

        $response = DB::transaction(function () use ($template, $vacancy, $application, $user, $member, $schema, $answers, $validated, $score) {
            $record = FormResponse::query()->firstOrNew([
                'vacancy_application_id' => $application->id,
                'user_id' => $user->id,
            ]);
            if ($record->exists && $record->submitted_at) {
                abort(response()->json([
                    'message' => 'This score sheet has been submitted and cannot be changed.',
                ], 422));
            }
            $record->fill([
                'form_template_id' => $template->id,
                'vacancy_id' => $vacancy->id,
                'panel_member_id' => $member?->id,
                'schema_snapshot' => $schema,
                'answers' => $answers,
                'comments' => filled($validated['comments'] ?? null) ? (string) $validated['comments'] : null,
                'total_score' => $score['total'],
                'max_score' => $score['max'],
                'submitted_at' => ($validated['submit'] ?? true) ? now() : $record->submitted_at,
            ]);
            $record->save();

            return $record->load(['panelMember', 'user']);
        });

        return response()->json([
            'message' => $response->submitted_at ? 'Score saved.' : 'Draft saved.',
            'data' => $response->present(),
        ]);
    }

    private function assertShortlisted(VacancyApplication $application): void
    {
        $application->loadMissing('candidateStage');
        if (! $application->candidateStage?->is_shortlist) {
            abort(response()->json([
                'message' => 'Score sheets are only available for shortlisted candidates.',
            ], 422));
        }
    }

    /**
     * @param  Collection<int, FormResponse>  $responses
     * @return list<array<string, mixed>>
     */
    private function fieldTotals(Collection $responses, ?FormTemplate $template): array
    {
        if (! $template) {
            return [];
        }

        $totals = [];
        foreach (FormSchema::inputFields($template->normalizedSchema()) as $field) {
            if (($field['type'] ?? '') !== 'score') {
                continue;
            }
            $sum = 0.0;
            $count = 0;
            foreach ($responses as $response) {
                $answers = is_array($response->answers) ? $response->answers : [];
                $value = $answers[(string) ($field['id'] ?? '')] ?? null;
                if (is_numeric($value)) {
                    $sum += (float) $value;
                    $count++;
                }
            }
            $totals[] = [
                'id' => $field['id'] ?? null,
                'label' => $field['label'] ?? 'Score',
                'total' => round($sum, 2),
                'average' => $count > 0 ? round($sum / $count, 2) : null,
                'max' => $field['max'] ?? null,
            ];
        }

        return $totals;
    }

    /**
     * @return Collection<int, VacancyApplication>
     */
    private function shortlistedApplications(Vacancy $vacancy): Collection
    {
        return VacancyApplication::query()
            ->with(VacancyApplication::defaultRelations())
            ->where('vacancy_id', $vacancy->id)
            ->whereHas('candidateStage', fn ($query) => $query->where('is_shortlist', true))
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();
    }

    private function resolveTemplate(Vacancy $vacancy): FormTemplate
    {
        $template = $vacancy->formTemplate;
        if (! $template || ! $template->is_active) {
            abort(response()->json([
                'message' => 'Assign an active score sheet to this vacancy first.',
            ], 422));
        }

        return $template;
    }
}
