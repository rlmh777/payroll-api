<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeePoolPoint;
use App\Models\PoolDistributionType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EmployeePoolPointController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = EmployeePoolPoint::query()->with(['poolDistributionType', 'createdByUser:id,name,email']);

        if ($request->filled('employeeId') || $request->filled('employee_id')) {
            $query->where('employee_id', $request->input('employeeId', $request->input('employee_id')));
        }

        if ($request->filled('pool_distribution_type_id')) {
            $query->where('pool_distribution_type_id', $request->integer('pool_distribution_type_id'));
        }

        if ($request->boolean('current_only')) {
            $asOf = $request->filled('as_of')
                ? $request->date('as_of')->format('Y-m-d')
                : now()->toDateString();

            $query->whereDate('effective_date', '<=', $asOf)
                ->where(function ($builder) use ($asOf) {
                    $builder->whereNull('end_date')
                        ->orWhereDate('end_date', '>=', $asOf);
                });
        }

        $query->orderByDesc('effective_date')->orderByDesc('created_at');

        return response()->json($query->paginate((int) $request->get('per_page', 50)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'uuid', 'exists:employee,id'],
            'pool_distribution_type_id' => ['required', 'integer', 'exists:pool_distribution_type,id'],
            'points' => ['required', 'numeric', 'min:0'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'effective_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:effective_date'],
            'notes' => ['nullable', 'string'],
        ]);

        $this->closeOpenHistory(
            $validated['employee_id'],
            (int) $validated['pool_distribution_type_id'],
            $validated['effective_date'],
        );

        $record = EmployeePoolPoint::create([
            'id' => (string) Str::uuid(),
            'employee_id' => $validated['employee_id'],
            'pool_distribution_type_id' => $validated['pool_distribution_type_id'],
            'points' => $validated['points'],
            'weight' => $validated['weight'] ?? 1,
            'effective_date' => $validated['effective_date'],
            'end_date' => $validated['end_date'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'created_by' => Auth::id(),
        ]);

        return response()->json([
            'message' => 'Pool points recorded successfully',
            'data' => $record->load(['poolDistributionType', 'createdByUser:id,name,email']),
        ], 201);
    }

    public function show(EmployeePoolPoint $employeePoolPoint): JsonResponse
    {
        return response()->json($employeePoolPoint->load(['poolDistributionType', 'createdByUser:id,name,email']));
    }

    public function update(Request $request, EmployeePoolPoint $employeePoolPoint): JsonResponse
    {
        $validated = $request->validate([
            'points' => ['sometimes', 'numeric', 'min:0'],
            'weight' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'effective_date' => ['sometimes', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:effective_date'],
            'notes' => ['nullable', 'string'],
            'pool_distribution_type_id' => [
                'sometimes',
                'integer',
                Rule::exists(PoolDistributionType::class, 'id'),
            ],
        ]);

        $employeePoolPoint->update($validated);

        return response()->json([
            'message' => 'Pool points updated successfully',
            'data' => $employeePoolPoint->fresh()->load(['poolDistributionType', 'createdByUser:id,name,email']),
        ]);
    }

    public function destroy(EmployeePoolPoint $employeePoolPoint): JsonResponse
    {
        $employeePoolPoint->delete();

        return response()->json(['message' => 'Pool points deleted successfully']);
    }

    public function currentForEmployee(Employee $employee, Request $request): JsonResponse
    {
        $asOf = $request->filled('as_of')
            ? $request->date('as_of')->format('Y-m-d')
            : now()->toDateString();

        $types = PoolDistributionType::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $points = EmployeePoolPoint::query()
            ->where('employee_id', $employee->id)
            ->whereDate('effective_date', '<=', $asOf)
            ->where(function ($builder) use ($asOf) {
                $builder->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $asOf);
            })
            ->orderByDesc('effective_date')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('pool_distribution_type_id')
            ->map(fn ($group) => $group->first());

        $data = $types->map(function (PoolDistributionType $type) use ($points) {
            $point = $points->get($type->id);

            return [
                'poolDistributionType' => $type,
                'points' => $point ? round((float) $point->points, 4) : 0,
                'weight' => $point ? round((float) $point->weight, 4) : 1,
                'weightedPoints' => $point ? $point->weightedPoints() : 0,
                'effectiveDate' => optional($point?->effective_date)->format('Y-m-d'),
                'notes' => $point?->notes,
                'recordId' => $point?->id,
            ];
        })->values();

        return response()->json(['data' => $data, 'asOf' => $asOf]);
    }

    private function closeOpenHistory(string $employeeId, int $typeId, string $effectiveDate): void
    {
        $dayBefore = date('Y-m-d', strtotime($effectiveDate . ' -1 day'));

        EmployeePoolPoint::query()
            ->where('employee_id', $employeeId)
            ->where('pool_distribution_type_id', $typeId)
            ->whereNull('end_date')
            ->whereDate('effective_date', '<', $effectiveDate)
            ->update(['end_date' => $dayBefore]);
    }
}
