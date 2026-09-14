<?php

namespace App\Http\Controllers;

use App\Models\AuthSetting;
use App\Models\User;
use App\Support\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AuthSettingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! Access::can($actor, 'manager-users')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json(AuthSetting::current());
    }

    public function update(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! Access::can($actor, 'manager-users')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validator = Validator::make($request->all(), [
            'two_factor_policy' => ['sometimes', 'in:off,optional,required'],
            'passkeys_enabled' => ['sometimes', 'boolean'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $settings = AuthSetting::current();
        $settings->fill($request->only(['two_factor_policy', 'passkeys_enabled']));
        $settings->save();

        return response()->json($settings);
    }

    public function updateUserTwoFactor(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! Access::can($actor, 'manager-users')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validator = Validator::make($request->all(), [
            'two_factor_required' => ['nullable', 'boolean'],
            'reset_two_factor' => ['sometimes', 'boolean'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($request->has('two_factor_required')) {
            $user->two_factor_required = $request->input('two_factor_required');
        }

        if ($request->boolean('reset_two_factor')) {
            $user->two_factor_secret = null;
            $user->two_factor_recovery_codes = null;
            $user->two_factor_confirmed_at = null;
        }

        $user->save();

        return response()->json([
            'message' => 'User security settings updated',
            'user' => [
                'id' => $user->id,
                'two_factor_required' => $user->two_factor_required,
                'two_factor_enabled' => $user->hasTwoFactorEnabled(),
                'passkey_count' => $user->webAuthnCredentials()->count(),
            ],
        ]);
    }
}
