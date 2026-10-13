<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Access;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserTokenController extends Controller
{
    public function index(Request $request, User $user): JsonResponse
    {
        $this->assertCanManageTokens($request->user());

        $tokens = $user->tokens()
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'created_at', 'last_used_at', 'expires_at'])
            ->map(fn ($token) => $this->present($token));

        return response()->json(['data' => $tokens]);
    }

    public function store(Request $request, User $user): JsonResponse
    {
        $this->assertCanManageTokens($request->user());

        $validated = $request->validate([
            'tokenName' => ['required', 'string', 'max:255'],
            'expiresAt' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        $expiresAt = $this->resolveExpiresAt($validated['expiresAt'] ?? null);

        // Sanctum abilities stay open; API authorization uses this user's Spatie roles/permissions.
        $created = $user->createToken(trim($validated['tokenName']), ['*'], $expiresAt);

        return response()->json([
            'message' => 'API token created. Copy it now; it will not be shown again.',
            'token' => $created->plainTextToken,
            'data' => $this->present($created->accessToken),
        ], 201);
    }

    public function destroy(Request $request, User $user, string $tokenId): JsonResponse
    {
        $this->assertCanManageTokens($request->user());

        $token = $user->tokens()->where('id', $tokenId)->first();
        if (! $token) {
            return response()->json(['message' => 'Token not found.'], 404);
        }

        $token->delete();

        return response()->json(['message' => 'API token revoked.']);
    }

    private function present(object $token): array
    {
        return [
            'id' => (string) $token->id,
            'name' => $token->name,
            'createdAt' => optional($token->created_at)?->toIso8601String(),
            'lastUsedAt' => optional($token->last_used_at)?->toIso8601String(),
            'expiresAt' => optional($token->expires_at)?->toIso8601String(),
        ];
    }

    private function resolveExpiresAt(?string $value): Carbon
    {
        if ($value === null || $value === '') {
            return now()->addYear();
        }

        $parsed = Carbon::parse($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $parsed->endOfDay();
        }

        return $parsed;
    }

    private function assertCanManageTokens(?User $actor): void
    {
        if (! $actor) {
            abort(401, 'Unauthenticated.');
        }

        if (! Access::canManageAllUserPasswords($actor)) {
            abort(403, 'You are not allowed to manage API tokens.');
        }
    }
}
