<?php

namespace App\Http\Controllers;

use App\Services\NavigationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NavigationController extends Controller
{
    public function __construct(
        private readonly NavigationService $navigationService,
    ) {}

    public function session(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        return response()->json($this->navigationService->forUser($user));
    }
}
