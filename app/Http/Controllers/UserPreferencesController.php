<?php

namespace App\Http\Controllers;

use App\Models\Menu;
use App\Models\User;
use App\Services\NavigationService;
use App\Support\AuthUserPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserPreferencesController extends Controller
{
    public function __construct(
        private readonly NavigationService $navigationService,
    ) {}

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
            'defaultMenu' => ['sometimes', 'nullable', 'string', 'max:255'],
            'textSize' => ['sometimes', 'string', Rule::in(['sm', 'md', 'lg'])],
            'onboardingEnabled' => ['sometimes', 'boolean'],
            'onboardingCompleted' => ['sometimes', 'boolean'],
            'onboardingSeen' => ['sometimes', 'array'],
            'onboardingSeen.*' => ['boolean'],
            'passkeyPrompt' => ['sometimes', 'string', Rule::in(['prompt', 'never'])],
        ]);

        if ($validated === []) {
            return response()->json([
                'message' => 'No preferences were provided.',
            ], 422);
        }

        $preferences = is_array($user->preferences) ? $user->preferences : [];
        $moduleCode = $validated['defaultModule'] ?? $user->defaultModule();

        if (array_key_exists('defaultModule', $validated)) {
            $this->assertUserCanAccessModule($user, $validated['defaultModule']);
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
        if (array_key_exists('textSize', $validated)) {
            $preferences['text_size'] = $validated['textSize'];
        }
        if (array_key_exists('passkeyPrompt', $validated)) {
            $preferences['passkey_prompt'] = $validated['passkeyPrompt'];
        }
        if (array_key_exists('defaultMenu', $validated)) {
            $route = is_string($validated['defaultMenu']) ? trim($validated['defaultMenu']) : '';
            if ($route === '') {
                unset($preferences['default_menu']);
            } else {
                $this->assertMenuBelongsToModule($route, $moduleCode);
                $preferences['default_menu'] = $route;
            }
        } elseif (array_key_exists('defaultModule', $validated)) {
            $existingMenu = is_string($preferences['default_menu'] ?? null) ? $preferences['default_menu'] : null;
            if ($existingMenu && ! $this->menuBelongsToModule($existingMenu, $validated['defaultModule'])) {
                unset($preferences['default_menu']);
            }
        }

        $user->preferences = $preferences;
        $user->save();

        return response()->json(AuthUserPresenter::present($user->fresh()));
    }

    private function assertUserCanAccessModule(User $user, string $moduleCode): void
    {
        $accessible = $this->navigationService->accessibleModuleCodes($user);
        if ($accessible === [] || in_array($moduleCode, $accessible, true)) {
            return;
        }

        throw ValidationException::withMessages([
            'defaultModule' => count($accessible) === 1
                ? 'You only have access to one application.'
                : 'Choose an application you have permission to use.',
        ]);
    }

    private function assertMenuBelongsToModule(string $route, string $moduleCode): void
    {
        if ($this->menuBelongsToModule($route, $moduleCode)) {
            return;
        }

        throw ValidationException::withMessages([
            'defaultMenu' => 'Choose a menu from the selected application.',
        ]);
    }

    private function menuBelongsToModule(string $route, string $moduleCode): bool
    {
        return Menu::query()
            ->where('route', $route)
            ->where('module_code', $moduleCode)
            ->where('is_active', true)
            ->exists();
    }
}
