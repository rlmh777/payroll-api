<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\PoolDistributionType;
use App\Models\PoolDistributionTypeDepartmentShare;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PoolDistributionTypeController extends Controller
{
    private const MODES = [
        PoolDistributionType::MODE_WEIGHTED_POINTS,
        PoolDistributionType::MODE_EQUAL_SHARE,
        PoolDistributionType::MODE_DEPARTMENT_EQUAL_SHARE,
        PoolDistributionType::MODE_MANUAL,
        PoolDistributionType::MODE_DISABLED,
    ];

    public function index(Request $request): JsonResponse
    {
        $query = PoolDistributionType::query()->with(['payrollEarningCode', 'allowance', 'departmentShares.department']);

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'ilike', "%{$search}%")
                    ->orWhere('code', 'ilike', "%{$search}%");
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->boolean('distributable_only')) {
            $query->where('is_active', true)
                ->whereIn('calculation_mode', [
                    PoolDistributionType::MODE_WEIGHTED_POINTS,
                    PoolDistributionType::MODE_EQUAL_SHARE,
                    PoolDistributionType::MODE_DEPARTMENT_EQUAL_SHARE,
                ]);
        }

        $sortField = $request->get('sort_by', 'sort_order');
        $sortDirection = $request->get('sort_direction', 'asc');
        if (in_array($sortField, ['name', 'code', 'sort_order', 'calculation_mode'], true)) {
            $query->orderBy($sortField, $sortDirection);
        } else {
            $query->orderBy('sort_order')->orderBy('name');
        }

        if ($request->boolean('all')) {
            return response()->json(['data' => $query->get()]);
        }

        return response()->json($query->paginate((int) $request->get('per_page', 15)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());
        $type = PoolDistributionType::create($this->normalize($validated));
        $this->syncDepartmentShares($type, $validated['department_shares'] ?? null);

        return response()->json([
            'message' => 'Pool distribution type created successfully',
            'data' => $type->load(['payrollEarningCode', 'allowance', 'departmentShares.department']),
        ], 201);
    }

    public function show(PoolDistributionType $poolDistributionType): JsonResponse
    {
        return response()->json($poolDistributionType->load(['payrollEarningCode', 'allowance', 'departmentShares.department']));
    }

    public function update(Request $request, PoolDistributionType $poolDistributionType): JsonResponse
    {
        $validated = $request->validate($this->rules($poolDistributionType->id));
        $poolDistributionType->update($this->normalize($validated));
        $this->syncDepartmentShares($poolDistributionType, $validated['department_shares'] ?? null);

        return response()->json([
            'message' => 'Pool distribution type updated successfully',
            'data' => $poolDistributionType->fresh()->load(['payrollEarningCode', 'allowance', 'departmentShares.department']),
        ]);
    }

    public function destroy(PoolDistributionType $poolDistributionType): JsonResponse
    {
        $poolDistributionType->delete();

        return response()->json(['message' => 'Pool distribution type deleted successfully']);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?int $ignoreId = null): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:64',
                Rule::unique('pool_distribution_type', 'code')->ignore($ignoreId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'calculation_mode' => ['required', Rule::in(self::MODES)],
            'is_active' => ['sometimes', 'boolean'],
            'requires_hours_eligibility' => ['sometimes', 'boolean'],
            'payroll_earning_code_id' => ['nullable', 'integer', 'exists:payroll_earning_code,id'],
            'allowance_id' => ['nullable', 'uuid', 'exists:allowance,id'],
            'is_taxable' => ['sometimes', 'boolean'],
            'is_ss_subject' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
            'department_shares' => ['nullable', 'array'],
            'department_shares.*.department_id' => ['required_with:department_shares', 'integer', 'exists:department,id'],
            'department_shares.*.percent' => ['required_with:department_shares', 'numeric', 'min:0.01', 'max:100'],
        ];
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function normalize(array $validated): array
    {
        return [
            'code' => strtoupper(trim((string) $validated['code'])),
            'name' => trim((string) $validated['name']),
            'calculation_mode' => $validated['calculation_mode'],
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'requires_hours_eligibility' => (bool) ($validated['requires_hours_eligibility'] ?? true),
            'payroll_earning_code_id' => $validated['payroll_earning_code_id'] ?? null,
            'allowance_id' => $validated['allowance_id'] ?? null,
            'is_taxable' => (bool) ($validated['is_taxable'] ?? true),
            'is_ss_subject' => (bool) ($validated['is_ss_subject'] ?? true),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'notes' => $validated['notes'] ?? null,
        ];
    }

    /**
     * @param  list<array{department_id:int, percent:float|int|string}>|null  $shares
     */
    private function syncDepartmentShares(PoolDistributionType $type, ?array $shares): void
    {
        $mode = $type->calculation_mode;
        if ($mode !== PoolDistributionType::MODE_DEPARTMENT_EQUAL_SHARE) {
            if ($shares !== null) {
                $type->departmentShares()->delete();
            }

            return;
        }

        $rows = collect($shares ?? [])
            ->map(fn (array $row) => [
                'department_id' => (int) ($row['department_id'] ?? 0),
                'percent' => round((float) ($row['percent'] ?? 0), 2),
            ])
            ->filter(fn (array $row) => $row['department_id'] > 0 && $row['percent'] > 0)
            ->unique('department_id')
            ->values();

        $sum = round($rows->sum('percent'), 2);
        if ($rows->isEmpty() || abs($sum - 100) > 0.009) {
            throw ValidationException::withMessages([
                'department_shares' => 'Department tip percentages must be configured and add up to 100%.',
            ]);
        }

        $type->departmentShares()->delete();
        foreach ($rows as $row) {
            PoolDistributionTypeDepartmentShare::query()->create([
                'pool_distribution_type_id' => $type->id,
                'department_id' => $row['department_id'],
                'percent' => $row['percent'],
            ]);
        }
    }
}
