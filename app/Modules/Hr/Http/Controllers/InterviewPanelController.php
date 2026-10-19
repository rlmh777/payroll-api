<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Modules\Hr\Models\FormResponse;
use App\Modules\Hr\Models\Vacancy;
use App\Modules\Hr\Models\VacancyApplication;
use App\Modules\Hr\Models\VacancyPanelMember;
use App\Modules\Hr\Support\FormSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InterviewPanelController extends Controller
{
    public function assignments(Request $request): JsonResponse
    {
        $user = $request->user();
        $memberships = VacancyPanelMember::query()
            ->with(['vacancy.formTemplate', 'vacancy.jobTitle', 'vacancy.stage', 'recommendedApplication.applicant'])
            ->where('status', '!=', VacancyPanelMember::STATUS_REVOKED)
            ->where(function ($query) use ($user) {
                $query->where('user_id', $user?->id)
                    ->orWhereRaw('LOWER(email) = ?', [mb_strtolower((string) $user?->email)]);
            })
            ->orderByDesc('invited_at')
            ->get();

        $data = $memberships
            ->filter(fn (VacancyPanelMember $member) => $member->vacancy)
            ->map(function (VacancyPanelMember $member) use ($user) {
                $vacancy = $member->vacancy;
                $applications = VacancyApplication::query()
                    ->with(VacancyApplication::defaultRelations())
                    ->where('vacancy_id', $vacancy->id)
                    ->orderByDesc('created_at')
                    ->get();
                $myScores = FormResponse::query()
                    ->where('user_id', $user?->id)
                    ->where('vacancy_id', $vacancy->id)
                    ->get()
                    ->keyBy('vacancy_application_id');

                return [
                    'panel_member' => $member->present(),
                    'vacancy' => $vacancy->present(),
                    'form_template' => $vacancy->formTemplate?->present(),
                    'applications' => $applications->map(function (VacancyApplication $application) use ($myScores) {
                        $mine = $myScores->get($application->id);

                        return array_merge($application->present(), [
                            'my_score' => $mine?->present(includeAnswers: false),
                        ]);
                    })->values(),
                ];
            })
            ->values();

        return response()->json(['data' => $data]);
    }

    public function showScore(Request $request, Vacancy $vacancy, VacancyApplication $application): JsonResponse
    {
        $this->assertAssigned($request, $vacancy);
        if ($application->vacancy_id !== $vacancy->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $template = $vacancy->formTemplate;
        $mine = FormResponse::query()
        $application->loadMissing('candidateStage');
        if (! $application->candidateStage?->is_shortlist) {
            return response()->json([
                'message' => 'Score sheets are only available for shortlisted candidates.',
            ], 422);
        }

            ->with(['panelMember', 'user'])
            ->where('vacancy_application_id', $application->id)
            ->where('user_id', $request->user()?->id)
            ->first();

        return response()->json([
            'vacancy' => $vacancy->load(Vacancy::defaultRelations())->present(),
        if ($mine?->submitted_at) {
            return response()->json([
                'message' => 'This score sheet has been submitted and can no longer be opened.',
            ], 403);
        }

            'application' => $application->load(VacancyApplication::defaultRelations())->present(),
            'form_template' => $template?->present(),
            'data' => $mine?->present() ?? [
                'schema' => $template?->normalizedSchema() ?? ['fields' => []],
                'answers' => [],
                'comments' => null,
                'is_submitted' => false,
                'total_score' => null,
                'max_score' => $template ? FormSchema::score($template->normalizedSchema(), [])['max'] : null,
            ],
        ]);
    }

    private function assertAssigned(Request $request, Vacancy $vacancy): void
    {
        $user = $request->user();
        $assigned = VacancyPanelMember::query()
            ->where('vacancy_id', $vacancy->id)
            ->where('status', '!=', VacancyPanelMember::STATUS_REVOKED)
            ->where(function ($query) use ($user) {
                $query->where('user_id', $user?->id)
                    ->orWhereRaw('LOWER(email) = ?', [mb_strtolower((string) $user?->email)]);
            })
            ->exists();

        if (! $assigned && ! $user?->can('view-vacancies')) {
            abort(response()->json(['message' => 'You are not on this interview panel.'], 403));
        if (! $assigned) {
            abort(response()->json(['message' => 'You are not on this interview panel.'], 403));
        }
    }

    public function recommend(Request $request, Vacancy $vacancy): JsonResponse
    {
        $member = $this->activeMember($request, $vacancy);
        $validated = $request->validate([
            'application_id' => ['required', 'uuid'],
        ]);

        $application = VacancyApplication::query()
            ->where('vacancy_id', $vacancy->id)
            ->whereKey($validated['application_id'])
            ->whereHas('candidateStage', fn ($query) => $query->where('is_shortlist', true))
            ->first();
        if (! $application) {
            return response()->json(['message' => 'Choose a shortlisted candidate.'], 422);
        }

        $member->recommended_application_id = $application->id;
        $member->save();

        return response()->json([
            'message' => 'Recommendation saved. HR makes the final hiring decision.',
            'data' => $member->fresh()?->present(),
        ]);
    }

    private function activeMember(Request $request, Vacancy $vacancy): VacancyPanelMember
    {
        $user = $request->user();
        $member = VacancyPanelMember::query()
            ->where('vacancy_id', $vacancy->id)
            ->where('status', '!=', VacancyPanelMember::STATUS_REVOKED)
            ->where(function ($query) use ($user) {
                $query->where('user_id', $user?->id)
                    ->orWhereRaw('LOWER(email) = ?', [mb_strtolower((string) $user?->email)]);
            })
            ->first();

        if (! $member) {
            abort(response()->json(['message' => 'You are not on this interview panel.'], 403));
        }

        return $member;
    public function saveScore(Request $request, Vacancy $vacancy, VacancyApplication $application): JsonResponse
    {
        $this->assertAssigned($request, $vacancy);

        return app(VacancyPanelController::class)->upsertScore($request, $vacancy, $application);
    }
}
