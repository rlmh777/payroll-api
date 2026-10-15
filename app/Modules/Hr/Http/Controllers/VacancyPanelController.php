<?php

namespace App\Modules\Hr\Http\Controllers;

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
use Illuminate\Support\Facades\DB;

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
            'email' => ['required', 'email', 'max:255'],
        ]);

        $result = $this->invites->invite(
            $vacancy,
            (string) $validated['name'],
            (string) $validated['email'],
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
        $responses = FormResponse::query()
            ->with(['panelMember', 'user'])
            ->where('vacancy_application_id', $application->id)
            ->orderByDesc('submitted_at')
            ->orderByDesc('updated_at')
            ->get();

        $submitted = $responses->whereNotNull('submitted_at');
        $average = $submitted->avg('total_score');

        return response()->json([
            'form_template' => $template?->present(),
            'summary' => [
                'count' => $submitted->count(),
                'average' => $average !== null ? round((float) $average, 2) : null,
                'max_score' => $template ? FormSchema::score($template->normalizedSchema(), [])['max'] : null,
            ],
            'data' => $responses->map(fn (FormResponse $response) => $response->present())->values(),
        ]);
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
        $member = VacancyPanelMember::query()
            ->where('vacancy_id', $vacancy->id)
            ->where(function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->orWhereRaw('LOWER(email) = ?', [mb_strtolower((string) $user->email)]);
            })
            ->where('status', '!=', VacancyPanelMember::STATUS_REVOKED)
            ->first();

        $response = DB::transaction(function () use ($template, $vacancy, $application, $user, $member, $schema, $answers, $validated, $score) {
            $record = FormResponse::query()->firstOrNew([
                'vacancy_application_id' => $application->id,
                'user_id' => $user->id,
            ]);
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
