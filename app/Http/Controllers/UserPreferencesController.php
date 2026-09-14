<?php

namespace App\Http\Controllers;

use App\Support\AuthUserPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserPreferencesController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'defaultModule' => [
                'required',
                'string',
                'max:64',
                Rule::exists('modules', 'code')->where(fn ($query) => $query->where('is_active', true)),
            ],
        ]);

        $preferences = is_array($user->preferences) ? $user->preferences : [];
        $preferences['default_module'] = $validated['defaultModule'];
        $user->preferences = $preferences;
        $user->save();

        return response()->json(AuthUserPresenter::present($user->fresh()));
    }
}
