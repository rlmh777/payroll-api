<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class TwoFactorController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
    ) {
    }

    public function verifyLogin(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'challenge_key' => ['required', 'string'],
            'code' => ['required', 'string', 'max:64'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            return response()->json($this->twoFactor->verifyChallenge(
                (string) $request->input('challenge_key'),
                (string) $request->input('code'),
            ));
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 400);
        }
    }

    public function setupLoginOptions(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'challenge_key' => ['required', 'string'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $challengeKey = (string) $request->input('challenge_key');
        $cached = Cache::get($challengeKey);
        if (! is_array($cached) || ($cached['purpose'] ?? null) !== 'setup') {
            return response()->json(['message' => 'Two-factor setup challenge expired or invalid.'], 400);
        }

        $user = User::query()->find($cached['user_id'] ?? null);
        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        try {
            return response()->json($this->twoFactor->beginSetup($user, $challengeKey));
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 400);
        }
    }

    public function setupLoginConfirm(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'challenge_key' => ['required', 'string'],
            'code' => ['required', 'string', 'max:64'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $challengeKey = (string) $request->input('challenge_key');
        $cached = Cache::get($challengeKey);
        if (! is_array($cached) || ($cached['purpose'] ?? null) !== 'setup') {
            return response()->json(['message' => 'Two-factor setup challenge expired or invalid.'], 400);
        }

        $user = User::query()->find($cached['user_id'] ?? null);
        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        try {
            return response()->json($this->twoFactor->confirmSetup(
                $user,
                (string) $request->input('code'),
                $challengeKey,
            ));
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 400);
        }
    }

    public function setupOptions(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        try {
            $payload = $this->twoFactor->beginSetup($user, null);
            unset($payload['challenge_key']);

            return response()->json($payload);
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 400);
        }
    }

    public function confirm(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $validator = Validator::make($request->all(), [
            'code' => ['required', 'string', 'max:64'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $payload = $this->twoFactor->confirmSetup($user, (string) $request->input('code'), null);

            return response()->json([
                'message' => 'Two-factor authentication enabled',
                ...$payload,
            ]);
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 400);
        }
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if ($user->requiresTwoFactor($this->twoFactor->settings())) {
            return response()->json([
                'message' => 'Two-factor authentication is required by policy and cannot be disabled.',
            ], 403);
        }

        $this->twoFactor->disable($user);

        return response()->json(['message' => 'Two-factor authentication disabled']);
    }

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $settings = $this->twoFactor->settings();

        return response()->json([
            'enabled' => $user->hasTwoFactorEnabled(),
            'required' => $user->requiresTwoFactor($settings),
            'policy' => $settings->two_factor_policy,
            'recovery_codes_remaining' => is_array($user->two_factor_recovery_codes)
                ? count($user->two_factor_recovery_codes)
                : 0,
        ]);
    }
}
