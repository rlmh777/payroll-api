<?php

namespace App\Http\Controllers;

use App\Models\AuthSetting;
use App\Models\User;
use App\Services\UsernameGenerator;
use App\Support\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AuthSettingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! Access::can($actor, 'manager-users')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json($this->payload(AuthSetting::current()));
    }

    public function update(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! Access::can($actor, 'manager-users')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validator = Validator::make($request->all(), [
            'two_factor_policy' => ['sometimes', 'in:off,optional,required'],
            'passkeys_enabled' => ['sometimes', 'boolean'],
            'username_pattern' => ['sometimes', Rule::in(UsernameGenerator::PATTERNS)],
            'username_patterns' => ['sometimes', 'array', 'min:1'],
            'username_patterns.*' => ['distinct', Rule::in(UsernameGenerator::PATTERNS)],
            'username_separator' => ['sometimes', Rule::in(UsernameGenerator::SEPARATORS)],
            'username_include_middle_initial' => ['sometimes', 'boolean'],
            'employee_login_domain' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $settings = AuthSetting::current();
        $settings->fill($request->only([
            'two_factor_policy',
            'passkeys_enabled',
            'username_separator',
            'username_include_middle_initial',
            'employee_login_domain',
        ]));

        if ($request->exists('username_patterns')) {
            $patterns = UsernameGenerator::normalizePatterns($request->input('username_patterns'));
            $settings->username_patterns = $patterns;
            $settings->username_pattern = $patterns[0];
        } elseif ($request->exists('username_pattern')) {
            $patterns = UsernameGenerator::normalizePatterns($request->input('username_pattern'));
            $settings->username_patterns = $patterns;
            $settings->username_pattern = $patterns[0];
        }

        if ($request->exists('employee_login_domain')) {
            $domain = trim((string) $request->input('employee_login_domain', ''));
            $settings->employee_login_domain = $domain === '' ? null : ltrim($domain, '@');
        }

        $settings->save();

        return response()->json($this->payload($settings));
    }

    public function updateUserTwoFactor(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! Access::can($actor, 'manager-users')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validator = Validator::make($request->all(), [
            'two_factor_required' => ['nullable', 'boolean'],
            'reset_two_factor' => ['sometimes', 'boolean'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($request->has('two_factor_required')) {
            $user->two_factor_required = $request->input('two_factor_required');
        }

        if ($request->boolean('reset_two_factor')) {
            $user->two_factor_secret = null;
            $user->two_factor_recovery_codes = null;
            $user->two_factor_confirmed_at = null;
        }

        $user->save();

        return response()->json([
            'message' => 'User security settings updated',
            'user' => [
                'id' => $user->id,
                'two_factor_required' => $user->two_factor_required,
                'two_factor_enabled' => $user->hasTwoFactorEnabled(),
                'passkey_count' => $user->webAuthnCredentials()->count(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(AuthSetting $settings): array
    {
        $generator = new UsernameGenerator($settings);
        $candidates = $generator->previewCandidates('John', 'Doe', 'Michael');

        return [
            ...$settings->toArray(),
            'username_patterns' => $generator->patterns(),
            'username_pattern' => $generator->patterns()[0],
            'username_preview' => $candidates[0] ?? $generator->buildUsername('John', 'Doe'),
            'username_preview_with_middle' => $generator->buildUsername('John', 'Doe', 'Michael'),
            'username_preview_candidates' => $candidates,
        ];
    }
}
