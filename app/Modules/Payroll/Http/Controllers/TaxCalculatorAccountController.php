<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\TaxCalculatorAccount;
use App\Models\TaxCalculatorRate;
use App\Modules\Payroll\Services\TaxCalculatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TaxCalculatorAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = TaxCalculatorAccount::query()
            ->with(['account', 'parent', 'rates.rate'])
            ->orderBy('sort_order')
            ->orderBy('qb_code')
            ->orderBy('id');

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        return response()->json($query->get()->map(fn (TaxCalculatorAccount $account) => $this->accountPayload($account))->values());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);
        $lineKind = $validated['line_kind'] ?? TaxCalculatorAccount::lineKindFromAttributes(
            TaxCalculatorAccount::inferIsRollup($validated['qb_name']),
            $validated['qb_code'],
            $validated['qb_name'],
        );
        $isRollup = $lineKind === 'net_total';
        $rateAssignments = $this->collectRateAssignments($validated, $isRollup);
        unset(
            $validated['business_tax_codes'],
            $validated['gst_codes'],
        );

        $validated['sort_order'] = $validated['sort_order'] ?? 0;
        $validated['is_active'] = $validated['is_active'] ?? true;
        $validated['include_btb'] = $validated['include_btb'] ?? false;
        $validated['line_kind'] = $lineKind;
        $validated['is_rollup'] = $isRollup;
        $validated['tax_basis'] = $isRollup ? TaxCalculatorAccount::TAX_BASIS_NET : TaxCalculatorAccount::TAX_BASIS_NONE;

        $mapping = TaxCalculatorAccount::create($validated);
        $mapping->syncRateAssignments($rateAssignments);

        return response()->json([
            'message' => 'Tax calculator line saved successfully',
            'data' => $this->accountPayload($mapping->fresh()->load(['account', 'parent', 'rates.rate'])),
        ], 201);
    }

    public function show(TaxCalculatorAccount $taxCalculatorAccount): JsonResponse
    {
        return response()->json($this->accountPayload($taxCalculatorAccount->load(['account', 'parent', 'children', 'rates.rate'])));
    }

    public function update(Request $request, TaxCalculatorAccount $taxCalculatorAccount): JsonResponse
    {
        $validated = $this->validated($request, $taxCalculatorAccount->id);
        $lineKind = $validated['line_kind'] ?? $taxCalculatorAccount->lineKind();
        $isRollup = $lineKind === 'net_total';
        $rateAssignments = $this->collectRateAssignments($validated, $isRollup);
        unset(
            $validated['business_tax_codes'],
            $validated['gst_codes'],
        );

        $validated['line_kind'] = $lineKind;
        $validated['is_rollup'] = $isRollup;
        $validated['tax_basis'] = $isRollup ? TaxCalculatorAccount::TAX_BASIS_NET : TaxCalculatorAccount::TAX_BASIS_NONE;

        $taxCalculatorAccount->update($validated);
        $taxCalculatorAccount->syncRateAssignments($rateAssignments);

        return response()->json([
            'message' => 'Tax calculator line saved successfully',
            'data' => $this->accountPayload($taxCalculatorAccount->fresh()->load(['account', 'parent', 'rates.rate'])),
        ]);
    }

    public function destroy(TaxCalculatorAccount $taxCalculatorAccount): JsonResponse
    {
        $taxCalculatorAccount->delete();

        return response()->json(['message' => 'Tax calculator line deleted successfully']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?string $ignoreId = null): array
    {
        $businessCodes = $this->codesForCategory(TaxCalculatorService::CATEGORY_BUSINESS_TAX);
        $gstCodes = $this->codesForCategory(TaxCalculatorService::CATEGORY_GST);

        $validated = $request->validate([
            'qb_code' => ['required', 'string', 'max:64'],
            'qb_name' => ['required', 'string', 'max:255'],
            'line_kind' => ['nullable', 'string', Rule::in(['qb_account', 'net_total', 'section'])],
            'parent_id' => [
                'nullable',
                'uuid',
                'exists:tax_calculator_accounts,id',
                Rule::notIn(array_filter([$ignoreId])),
            ],
            'account_id' => [
                'nullable',
                'uuid',
                'exists:accounts,id',
                Rule::unique('tax_calculator_accounts', 'account_id')->ignore($ignoreId),
            ],
            'business_tax_code' => ['nullable', 'string', Rule::in($businessCodes)],
            'gst_code' => ['nullable', 'string', Rule::in($gstCodes)],
            'business_tax_codes' => ['nullable', 'array'],
            'business_tax_codes.*' => ['string', Rule::in($businessCodes)],
            'gst_codes' => ['nullable', 'array'],
            'gst_codes.*' => ['string', Rule::in($gstCodes)],
            'include_btb' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);

        $validated['qb_code'] = strtoupper(preg_replace('/\s+/', '', $validated['qb_code']) ?? $validated['qb_code']);

        if (! empty($validated['parent_id']) && $validated['parent_id'] === $ignoreId) {
            throw ValidationException::withMessages([
                'parent_id' => 'A line cannot be its own parent.',
            ]);
        }

        if (! empty($validated['parent_id'])) {
            $this->assertValidParent($validated['parent_id'], $ignoreId);
        }

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    private function accountPayload(TaxCalculatorAccount $account): array
    {
        $account->loadMissing(['rates.rate']);

        $payload = $account->toArray();
        $payload['line_kind'] = $account->lineKind();
        $payload['business_tax_codes'] = $account->businessTaxCodes();
        $payload['gst_codes'] = $account->gstTaxCodes();
        $payload['rate_assignments'] = $account->rateAssignments();

        if ($account->relationLoaded('parent') && $account->parent) {
            $payload['parent'] = [
                'id' => $account->parent->id,
                'qb_code' => $account->parent->qb_code,
                'qb_name' => $account->parent->qb_name,
                'line_kind' => $account->parent->lineKind(),
            ];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<array{code: string, tax_basis: string}>
     */
    private function collectRateAssignments(array $validated, bool $isRollup): array
    {
        $implicitBasis = $isRollup ? TaxCalculatorAccount::TAX_BASIS_NET : TaxCalculatorAccount::TAX_BASIS_GROSS;
        $assignments = [];

        foreach (array_merge(
            $validated['business_tax_codes'] ?? [],
            [$validated['business_tax_code'] ?? null],
            $validated['gst_codes'] ?? [],
            [$validated['gst_code'] ?? null],
        ) as $code) {
            $code = strtoupper(trim((string) $code));
            if ($code === '') {
                continue;
            }
            $assignments[] = ['code' => $code, 'tax_basis' => $implicitBasis];
        }

        $unique = [];
        foreach ($assignments as $assignment) {
            $unique[$assignment['code']] = $assignment;
        }

        return array_values($unique);
    }

    private function assertValidParent(string $parentId, ?string $accountId): void
    {
        $visited = [];
        $cursorId = $parentId;

        while ($cursorId !== null) {
            if ($cursorId === $accountId) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Parent line would create a circular hierarchy.',
                ]);
            }

            if (in_array($cursorId, $visited, true)) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Parent line is part of a circular hierarchy. Fix parent links in Settings first.',
                ]);
            }

            $visited[] = $cursorId;
            $cursorId = TaxCalculatorAccount::query()->whereKey($cursorId)->value('parent_id');
        }
    }

    /**
     * @return list<string>
     */
    private function codesForCategory(string $category): array
    {
        return TaxCalculatorRate::query()
            ->where('category', $category)
            ->where('applies_to_accounts', true)
            ->pluck('code')
            ->all();
    }
}
