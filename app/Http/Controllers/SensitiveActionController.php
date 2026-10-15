<?php

namespace App\Http\Controllers;

use App\Exceptions\SensitiveActionException;
use App\Models\User;
use App\Services\SensitiveActionChallengeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SensitiveActionController extends Controller
{
    public function __construct(
        private readonly SensitiveActionChallengeService $challenges,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in([
                SensitiveActionChallengeService::ACTION_DATABASE_RESTORE,
                SensitiveActionChallengeService::ACTION_LOG_CLEAR,
            ])],
            'context' => ['sometimes', 'array'],
            'context.source' => ['sometimes', 'nullable', 'string', 'in:backup,upload'],
            'context.backup_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'context.file' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        try {
            $payload = $this->challenges->issue(
                $user,
                (string) $validated['action'],
                is_array($validated['context'] ?? null) ? $validated['context'] : [],
            );
        } catch (SensitiveActionException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->status);
        }

        return response()->json([
            'message' => 'Confirmation code sent to '.$payload['sent_to'].'.',
            ...$payload,
        ]);
    }
}
