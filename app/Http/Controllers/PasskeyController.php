<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WebAuthnCredential;
use App\Services\PasskeyService;
use App\Services\TwoFactorService;
use App\Support\Access;
use App\Support\AuthUserPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PasskeyController extends Controller
{
    public function __construct(
        private readonly PasskeyService $passkeys,
        private readonly TwoFactorService $twoFactor,
    ) {
    }

    public function loginOptions(Request $request): JsonResponse
    {
        if (! $this->passkeys->enabled()) {
            return response()->json(['message' => 'Passkeys are disabled.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'email' => ['nullable', 'string', 'max:255'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $payload = $this->passkeys->authenticationOptions($request->input('email'));

        return response()->json($payload);
    }

    public function login(Request $request): JsonResponse
    {
        if (! $this->passkeys->enabled()) {
            return response()->json(['message' => 'Passkeys are disabled.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'challenge_key' => ['required', 'string'],
            'credential' => ['required', 'array'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $user = $this->passkeys->authenticate(
                (string) $request->input('challenge_key'),
                $request->input('credential', []),
            );
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 400);
        }

        return response()->json($this->twoFactor->completeStrongLogin($user));
    }

    public function registerOptions(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }
        if (! $this->passkeys->enabled()) {
            return response()->json(['message' => 'Passkeys are disabled.'], 403);
        }

        return response()->json($this->passkeys->registrationOptions($user));
    }

    public function register(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }
        if (! $this->passkeys->enabled()) {
            return response()->json(['message' => 'Passkeys are disabled.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'challenge_key' => ['required', 'string'],
            'credential' => ['required', 'array'],
            'name' => ['nullable', 'string', 'max:100'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $credential = $this->passkeys->register(
                $user,
                (string) $request->input('challenge_key'),
                $request->input('credential', []),
                $request->input('name'),
            );
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 400);
        }

        return response()->json([
            'message' => 'Passkey registered',
            'credential' => [
                'id' => $credential->id,
                'name' => $credential->name,
                'created_at' => $credential->created_at,
            ],
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $credentials = $user->webAuthnCredentials()
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'created_at', 'updated_at']);

        return response()->json($credentials);
    }

    public function destroy(Request $request, WebAuthnCredential $credential): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if ((string) $credential->user_id !== (string) $user->id && ! Access::can($user, 'manager-users')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $credential->delete();

        return response()->json(['message' => 'Passkey removed']);
    }
}
