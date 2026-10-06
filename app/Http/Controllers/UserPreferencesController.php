<?php

namespace App\Http\Controllers;

use App\Support\AuthUserPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserPreferencesController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'defaultModule' => [
                'sometimes',
                'string',
                'max:64',
                Rule::exists('modules', 'code')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'onboardingEnabled' => ['sometimes', 'boolean'],
            'onboardingCompleted' => ['sometimes', 'boolean'],
            'onboardingSeen' => ['sometimes', 'array'],
            'onboardingSeen.*' => ['boolean'],
        ]);

        if ($validated === []) {
            return response()->json([
                'message' => 'No preferences were provided.',
            ], 422);
        }

        $preferences = is_array($user->preferences) ? $user->preferences : [];

        if (array_key_exists('defaultModule', $validated)) {
            $preferences['default_module'] = $validated['defaultModule'];
        }
        if (array_key_exists('onboardingEnabled', $validated)) {
            $preferences['onboarding_enabled'] = (bool) $validated['onboardingEnabled'];
        }
        if (array_key_exists('onboardingCompleted', $validated)) {
            $preferences['onboarding_completed'] = (bool) $validated['onboardingCompleted'];
        }
        if (array_key_exists('onboardingSeen', $validated)) {
            $preferences['onboarding_seen'] = AuthUserPresenter::normalizeOnboardingSeen($validated['onboardingSeen']);
        }

        $user->preferences = $preferences;
        $user->save();

        return response()->json(AuthUserPresenter::present($user->fresh()));
    }
}
