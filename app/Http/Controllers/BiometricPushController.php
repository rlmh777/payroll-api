<?php

namespace App\Http\Controllers;

use App\Services\Biometric\BiometricPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BiometricPushController extends Controller
{
    public function __construct(
        private readonly BiometricPushService $push,
    ) {}

    public function register(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->push->register($request));
    }

    public function heartbeat(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->push->heartbeat($request));
    }

    public function push(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->push->ingest($request));
    }

    /**
     * @param  callable(): array<string, mixed>  $callback
     */
    private function respond(callable $callback): JsonResponse
    {
        try {
            return response()->json($callback());
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first() ?: $exception->getMessage(),
                'errors' => $exception->errors(),
            ], $exception->status);
        }
    }
}
