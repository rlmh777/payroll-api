<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function authenticate(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $user = User::query()
            ->where('email', $request->email)
            ->first();

        // if (
        //     !$user || is_null($user->email_verified_at)
        // ) {


        //     return response()->json([
        //         'message' => 'Email not verified',
        //     ], 500);
        // }

        if (
            !$user ||
            !Hash::check(
                $request->password,
                $user->password
            )
        ) {
            return response()->json([
                'message' => 'Mismatch Username or Password',
            ], 400);
        }

        $token = $user->createToken($request->email)->plainTextToken;
        return response()->json([
            'message' => 'Login successful',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->getRoleNames()->first() ?? 'employee',
            ],
            'token' => $token
        ], 200);

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

        $token = $user->createToken($request->token_name);
        
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
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated'
            ], 401);
        }

        // Revoke the current token
        $currentToken = $request->user()->currentAccessToken();
        if ($currentToken) {
            $currentToken->delete();
        }

        // Clear the session
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        
        return response()->json([
            'message' => 'Logged out successfully'
        ], 200);
    }

}
