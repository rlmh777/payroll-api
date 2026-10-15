<?php

namespace App\Http\Controllers;

use App\Models\AuthSetting;
use App\Models\User;
use App\Services\LoginLockoutService;
use App\Services\UserIpAllowlist;
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
            'lockout_max_attempts' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'lockout_decay_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
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
            'lockout_max_attempts',
            'lockout_decay_minutes',
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
            'unlock' => ['sometimes', 'boolean'],
            'allowed_ips' => ['sometimes', 'array', 'max:'.UserIpAllowlist::MAX_ENTRIES],
            'allowed_ips.*' => ['string', 'max:64', function (string $attribute, mixed $value, \Closure $fail) {
                if (! is_string($value) || ! UserIpAllowlist::isValidEntry($value)) {
                    $fail('Each allowed IP must be an IPv4/IPv6 address or CIDR range.');
                }
            }],
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

        if ($request->exists('allowed_ips')) {
            $user->allowed_ips = UserIpAllowlist::normalize($request->input('allowed_ips'));
        }

        if ($request->boolean('unlock')) {
            app(LoginLockoutService::class)->clear($user);
        }

        $user->save();

        return response()->json([
            'message' => 'User security settings updated',
            'user' => [
                'id' => $user->id,
                'two_factor_required' => $user->two_factor_required,
                'two_factor_enabled' => $user->hasTwoFactorEnabled(),
                'passkey_count' => $user->webAuthnCredentials()->count(),
                'allowed_ips' => UserIpAllowlist::normalize($user->allowed_ips),
                'locked_until' => $user->locked_until?->toIso8601String(),
                'is_locked' => $user->locked_until !== null && $user->locked_until->isFuture(),
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
