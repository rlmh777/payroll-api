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
        $hasAppraisalFields = $request->exists('appraisalReminderEnabled')
            || $request->exists('appraisalReminderIntervalValue')
            || $request->exists('appraisalReminderIntervalUnit');
        $hasProfileChange = $request->exists('profileChangeApproval');
        $partialUpdate = $hasContractFields || $hasAppraisalFields || $hasProfileChange;

        $validated = $request->validate([
            'birthdayVisibility' => [
                $partialUpdate ? 'sometimes' : 'required',
                'string',
                Rule::in(BirthdayVisibility::values()),
            ],
            'contractExpiryEnabled' => ['sometimes', 'boolean'],
            'contractExpiryOffsets' => ['sometimes', 'array', 'min:1', 'max:12'],
            'contractExpiryOffsets.*.value' => ['required', 'integer', 'min:1', 'max:36'],
            'contractExpiryOffsets.*.unit' => ['required', 'string', Rule::in(['days', 'weeks', 'months'])],
            'appraisalReminderEnabled' => ['sometimes', 'boolean'],
            'appraisalReminderIntervalValue' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'appraisalReminderIntervalUnit' => ['sometimes', 'string', Rule::in(['days', 'weeks'])],
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
        if (array_key_exists('appraisalReminderEnabled', $validated)
            || array_key_exists('appraisalReminderIntervalValue', $validated)
            || array_key_exists('appraisalReminderIntervalUnit', $validated)
        ) {
            $interval = HrSetting::normalizeReminderInterval(
                (int) ($validated['appraisalReminderIntervalValue'] ?? $setting->appraisal_reminder_interval_value ?: 2),
                (string) ($validated['appraisalReminderIntervalUnit'] ?? $setting->appraisal_reminder_interval_unit ?: 'days'),
            );
            $payload['appraisal_reminder_enabled'] = array_key_exists('appraisalReminderEnabled', $validated)
                ? (bool) $validated['appraisalReminderEnabled']
                : $setting->appraisalReminderEnabled();
            $payload['appraisal_reminder_interval_value'] = $interval['value'];
            $payload['appraisal_reminder_interval_unit'] = $interval['unit'];
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
     *     contractExpiryOffsets: list<array{value: int, unit: string}>,
     *     appraisalReminderEnabled: bool,
     *     appraisalReminderIntervalValue: int,
     *     appraisalReminderIntervalUnit: string
     * }
     */
    private function formatSetting(HrSetting $setting): array
    {
        $interval = $setting->appraisalReminderInterval();

        return [
            'birthdayVisibility' => $setting->birthdayVisibility()->value,
            'contractExpiryEnabled' => $setting->contractExpiryEnabled(),
            'contractExpiryOffsets' => $setting->contractExpiryOffsets(),
            'appraisalReminderEnabled' => $setting->appraisalReminderEnabled(),
            'appraisalReminderIntervalValue' => $interval['value'],
            'appraisalReminderIntervalUnit' => $interval['unit'],
        ];
    }
}
