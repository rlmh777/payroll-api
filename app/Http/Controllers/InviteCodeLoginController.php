<?php

namespace App\Http\Controllers;

use App\Modules\Hr\Services\PanelInviteService;
use App\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InviteCodeLoginController extends Controller
{
    public function __construct(
        private readonly PanelInviteService $invites,
        private readonly TwoFactorService $twoFactor,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string', 'max:32'],
        ]);

        $user = $this->invites->authenticate(
            (string) $validated['email'],
            (string) $validated['code'],
        );

        if (! $user) {
            return response()->json([
                'message' => 'Invalid email or invite code.',
            ], 400);
        }

        return response()->json($this->twoFactor->completeStrongLogin($user));
    }
}
