<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Modules\Hr\Services\PanelInviteService;
use App\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PanelAccessController extends Controller
{
    public function __construct(
        private readonly PanelInviteService $invites,
        private readonly TwoFactorService $twoFactor,
    ) {}

    public function show(string $token): JsonResponse
    {
        $member = $this->invites->findByToken($token);
        if (! $member || ! $this->invites->linkIsOpen($member)) {
            return response()->json([
                'message' => 'This score sheet link is no longer valid.',
            ], 410);
        }

        $member->loadMissing('vacancy');

        return response()->json([
            'data' => [
                'name' => $member->name,
                'email' => $member->email,
                'vacancy_title' => $member->vacancy?->title,
            ],
        ]);
    }

    public function verify(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ]);

        $member = $this->invites->findByToken($token);
        if (! $member || ! $this->invites->linkIsOpen($member)) {
            return response()->json([
                'message' => 'This score sheet link is no longer valid.',
            ], 410);
        }

        if (! $this->invites->codeMatches($member, (string) $validated['code'])) {
            return response()->json([
                'message' => 'That code does not match the one sent to your email.',
            ], 400);
        }

        $user = $this->invites->activate($member);

        return response()->json($this->twoFactor->completeStrongLogin($user));
    }
}
