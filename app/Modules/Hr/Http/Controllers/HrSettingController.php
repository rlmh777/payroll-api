<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Enums\BirthdayVisibility;
use App\Http\Controllers\Controller;
use App\Models\HrSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HrSettingController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json($this->formatSetting(HrSetting::current()));
    }

    public function update(Request $request): JsonResponse
    {
        $hasContractFields = $request->exists('contractExpiryEnabled')
            || $request->exists('contractExpiryOffsets');

        $validated = $request->validate([
            'birthdayVisibility' => [
                $hasContractFields ? 'sometimes' : 'required',
                'string',
                Rule::in(BirthdayVisibility::values()),
            ],
            'contractExpiryEnabled' => ['sometimes', 'boolean'],
            'contractExpiryOffsets' => ['sometimes', 'array', 'min:1', 'max:12'],
            'contractExpiryOffsets.*.value' => ['required', 'integer', 'min:1', 'max:36'],
            'contractExpiryOffsets.*.unit' => ['required', 'string', Rule::in(['days', 'weeks', 'months'])],
        ]);

        $setting = HrSetting::current();
        $payload = [];
        if (array_key_exists('birthdayVisibility', $validated)) {
            $payload['birthday_visibility'] = $validated['birthdayVisibility'];
        }
        if (array_key_exists('contractExpiryEnabled', $validated)) {
            $payload['contract_expiry_enabled'] = (bool) $validated['contractExpiryEnabled'];
        }
        if (array_key_exists('contractExpiryOffsets', $validated)) {
            $payload['contract_expiry_offsets'] = HrSetting::normalizeOffsets($validated['contractExpiryOffsets']);
        }

        if ($payload !== []) {
            $setting->update($payload);
            HrSetting::clearCurrentCache();
        }

        return response()->json([
            'message' => 'HR settings updated.',
            'data' => $this->formatSetting(HrSetting::current()),
        ]);
    }

    /**
     * @return array{
     *     birthdayVisibility: string,
     *     contractExpiryEnabled: bool,
     *     contractExpiryOffsets: list<array{value: int, unit: string}>
     * }
     */
    private function formatSetting(HrSetting $setting): array
    {
        return [
            'birthdayVisibility' => $setting->birthdayVisibility()->value,
            'contractExpiryEnabled' => $setting->contractExpiryEnabled(),
            'contractExpiryOffsets' => $setting->contractExpiryOffsets(),
        ];
    }
}
