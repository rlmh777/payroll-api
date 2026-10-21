<?php

namespace App\Http\Controllers;

use App\Exceptions\LoginAccessDeniedException;
use App\Services\LoginAccessService;
use App\Services\TwoFactorService;
use App\Support\LoginUserFinder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly LoginUserFinder $loginUsers,
        private readonly LoginAccessService $access,
    ) {
    }

    public function authenticate(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required_without:username', 'nullable', 'string', 'max:255'],
            'username' => ['required_without:email', 'nullable', 'string', 'max:255'],
            'password' => ['required'],
        ]);

        $identifier = trim((string) ($request->input('username') ?: $request->input('email')));
        $user = $this->loginUsers->find($identifier);

        try {
            if ($user) {
                $this->access->assertCanSignIn($user, $request);
            }

            if (
                ! $user ||
                ! Hash::check(
                    $request->password,
                    $user->password
                )
            ) {
                if ($user) {
                    $this->access->recordFailedAttempt($user);
                }

                return response()->json([
                    'message' => 'Mismatch Username or Password',
                ], 400);
            }

            // Keep password cost in sync without going through Auth::attempt.
            if (config('hashing.rehash_on_login', true) && Hash::needsRehash($user->password)) {
                $user->forceFill([
                    'password' => $request->password,
                ])->save();
            }
        } catch (LoginAccessDeniedException $exception) {
            return $exception->toResponse();
        }

        return response()->json($this->twoFactor->completePasswordLogin($user));
    }

    public function createToken(Request $request): JsonResponse
    {
        $request->validate([
            'token_name' => 'required|string|max:255'
        ]);

        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated'
            ], 401);
        }

        $token = $user->createToken($request->token_name, ['*'], now()->addHours(24));
        
        return response()->json([
            'message' => 'Token created successfully',
            'token' => $token->plainTextToken
        ], 201);
    }

    public function revokeToken(Request $request): JsonResponse
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated'
            ], 401);
        }

        // Get the current token before revoking it
        $currentToken = $request->user()->currentAccessToken();
        
        if ($currentToken) {
            // Revoke the current token
            $currentToken->delete();
        }
        
        return response()->json([
            'message' => 'Token revoked successfully'
        ], 200);
    }

    public function revokeAllTokens(Request $request): JsonResponse
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated'
            ], 401);
        }

        // Revoke all tokens for the user
        $user->tokens()->delete();
        
        return response()->json([
            'message' => 'All tokens revoked successfully'
        ], 200);
    }

    public function revokeSpecificToken(Request $request, $tokenId): JsonResponse
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated'
            ], 401);
        }

        // Find and revoke the specific token
        $token = $user->tokens()->where('id', $tokenId)->first();
        
        if (!$token) {
            return response()->json([
                'message' => 'Token not found'
            ], 404);
        }

        $token->delete();
        
        return response()->json([
            'message' => 'Token revoked successfully'
        ], 200);
    }

    public function listTokens(Request $request): JsonResponse
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated'
            ], 401);
        }

        $tokens = $user->tokens()->select('id', 'name', 'created_at', 'last_used_at')->get();
        
        return response()->json([
            'tokens' => $tokens
        ], 200);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->deletePresentedToken($request);

        try {
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        } catch (\Throwable) {
            // The API token is already deleted. A session error must not leave it behind.
        }

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    private function deletePresentedToken(Request $request): void
    {
        $plain = $request->bearerToken();
        if (is_string($plain) && $plain !== '') {
            PersonalAccessToken::findToken($plain)?->delete();
        }

        $current = $request->user()?->currentAccessToken();
        if ($current instanceof PersonalAccessToken) {
            $current->delete();
        }
    }

}
