<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\SocialSecurityContributionRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SocialSecurityContributionRuleController extends Controller
{
    private const METHODS = ['NONE', 'TIER_TABLE', 'FIXED_WEEKLY', 'RATE'];

    public function index(Request $request): JsonResponse
    {
        $query = SocialSecurityContributionRule::query()->orderByDesc('priority')->orderBy('name');

        if ($request->filled('state')) {
            $query->where('state', $request->input('state'));
        }

        return response()->json($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        $rule = SocialSecurityContributionRule::create($validated);

        return response()->json([
            'message' => 'Contribution rule created successfully',
            'data' => $rule,
        ], 201);
    }

    public function show(SocialSecurityContributionRule $socialSecurityContributionRule): JsonResponse
    {
        return response()->json($socialSecurityContributionRule);
    }

    public function update(Request $request, SocialSecurityContributionRule $socialSecurityContributionRule): JsonResponse
    {
        $validated = $this->validatePayload($request, true);

        $socialSecurityContributionRule->update($validated);

        return response()->json([
            'message' => 'Contribution rule updated successfully',
            'data' => $socialSecurityContributionRule->fresh(),
        ]);
    }

    public function destroy(SocialSecurityContributionRule $socialSecurityContributionRule): JsonResponse
    {
        if ($socialSecurityContributionRule->payrolls()->exists()) {
            return response()->json([
                'message' => 'Cannot delete contribution rule referenced on payroll records',
            ], 422);
        }

        $socialSecurityContributionRule->delete();

        return response()->json(['message' => 'Contribution rule deleted successfully']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, bool $partial = false): array
    {
        $ruleId = $request->route('socialSecurityContributionRule');
        $ignoreId = $ruleId instanceof SocialSecurityContributionRule ? $ruleId->id : $ruleId;

        $rules = [
            'code' => [$partial ? 'sometimes' : 'required', 'string', 'max:64', Rule::unique('social_security_contribution_rule', 'code')->ignore($ignoreId)],
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:128'],
            'description' => ['nullable', 'string'],
            'priority' => [$partial ? 'sometimes' : 'required', 'integer', 'min:0'],
            'employee_contribution_method' => [$partial ? 'sometimes' : 'required', Rule::in(self::METHODS)],
            'employer_contribution_method' => [$partial ? 'sometimes' : 'required', Rule::in(self::METHODS)],
            'employee_fixed_weekly_amount' => ['nullable', 'numeric', 'min:0'],
            'employer_fixed_weekly_amount' => ['nullable', 'numeric', 'min:0'],
            'employee_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'employer_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'skip_tier_lookup' => ['boolean'],
            'conditions' => [$partial ? 'sometimes' : 'required', 'array'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'state' => ['nullable', Rule::in(['active', 'inactive'])],
        ];

        return $request->validate($rules);
    }
}
