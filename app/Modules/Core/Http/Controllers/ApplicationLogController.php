<?php

namespace App\Modules\Core\Http\Controllers;

use App\Exceptions\SensitiveActionException;
use App\Models\User;
use App\Modules\Core\Services\ApplicationLogService;
use App\Services\SensitiveActionChallengeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ApplicationLogController extends Controller
{
    public function __construct(
        private readonly ApplicationLogService $applicationLogs,
        private readonly SensitiveActionChallengeService $challenges,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['nullable', 'string', 'max:255'],
            'lines' => ['nullable', 'integer', 'min:50', 'max:'.ApplicationLogService::MAX_LINES],
            'search' => ['nullable', 'string', 'max:255'],
            'level' => ['nullable', 'string', Rule::in([...ApplicationLogService::LEVELS, ''])],
        ]);

        try {
            return response()->json($this->applicationLogs->read(
                $validated['file'] ?? null,
                (int) ($validated['lines'] ?? ApplicationLogService::DEFAULT_LINES),
                isset($validated['search']) ? trim((string) $validated['search']) : null,
                isset($validated['level']) ? (string) $validated['level'] : null,
            ));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }
    }

    public function destroy(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'file' => ['nullable', 'string', 'max:255'],
            'challenge_key' => ['required', 'string'],
            'confirmation_code' => ['required', 'string', 'max:16'],
        ]);

        $file = isset($validated['file']) ? trim((string) $validated['file']) : null;
        $file = $file === '' ? null : $file;

        try {
            $this->challenges->consume(
                $actor,
                SensitiveActionChallengeService::ACTION_LOG_CLEAR,
                (string) $validated['challenge_key'],
                (string) $validated['confirmation_code'],
                ['file' => $file],
            );
            $cleared = $this->applicationLogs->clear($file);
        } catch (SensitiveActionException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->status);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 500);
        } catch (Throwable $exception) {
            return response()->json([
                'message' => $exception->getMessage() ?: 'Failed to clear application logs.',
            ], 500);
        }

        Log::info('Application logs cleared.', [
            'files' => $cleared,
            'user_id' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => $cleared === []
                ? 'No log files to clear.'
                : 'Application logs cleared.',
            'cleared' => $cleared,
        ]);
    }
}
