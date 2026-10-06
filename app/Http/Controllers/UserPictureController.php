<?php

namespace App\Http\Controllers;

use App\Support\AuthUserPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class UserPictureController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'picture' => 'required|image|mimes:jpeg,jpg,png,gif,webp|max:5120',
        ]);

        $user = $request->user();

        try {
            $user->storeProfilePicture($validated['picture']);

            return response()->json(AuthUserPresenter::present($user->fresh()));
        } catch (Throwable $exception) {
            Log::error('Error uploading profile picture', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to upload picture',
            ], 500);
        }
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        try {
            $user->clearProfilePicture();

            return response()->json(AuthUserPresenter::present($user->fresh()));
        } catch (Throwable $exception) {
            Log::error('Error removing profile picture', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to remove picture',
            ], 500);
        }
    }
}
