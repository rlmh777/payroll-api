<?php

namespace App\Http\Controllers;

use App\Services\MotdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserMotdController extends Controller
{
    public function __construct(
        private readonly MotdService $motd,
    ) {}

    public function claim(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['sometimes', 'string', Rule::in([MotdService::REASON_LOGIN, MotdService::REASON_DAILY])],
            'localDate' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        $message = $this->motd->claim(
            $request->user(),
            (string) ($validated['reason'] ?? MotdService::REASON_DAILY),
            $validated['localDate'] ?? null,
        );

        return response()->json([
            'message' => $message,
        ]);
    }
}
