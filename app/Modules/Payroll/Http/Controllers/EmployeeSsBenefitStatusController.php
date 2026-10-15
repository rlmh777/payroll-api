<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeSsBenefitStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EmployeeSsBenefitStatusController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = EmployeeSsBenefitStatus::query()->with(['employee', 'benefitType']);

        if ($request->filled('employeeId')) {
            $query->where('employeeId', $request->input('employeeId'));
        }

        if ($request->boolean('active_only') && $request->filled('as_of')) {
            $asOf = $request->input('as_of');
            $query->whereDate('effective_from', '<=', $asOf)
                ->where(function ($q) use ($asOf) {
                    $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $asOf);
                });
        }

        return response()->json(
            $query->orderByDesc('effective_from')->paginate((int) $request->get('per_page', 15))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employeeId' => ['required', 'uuid', 'exists:employee,id'],
            'is_receiving_benefit' => ['required', 'boolean'],
            'ss_benefit_type_id' => ['nullable', 'integer', 'exists:ss_benefit_type,id'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'verified_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $record = EmployeeSsBenefitStatus::create(array_merge($validated, [
            'id' => (string) Str::uuid(),
        ]));

        return response()->json([
            'message' => 'SS benefit status created successfully',
            'data' => $record->load(['employee', 'benefitType']),
        ], 201);
    }

    public function show(EmployeeSsBenefitStatus $employeeSsBenefitStatus): JsonResponse
    {
        return response()->json($employeeSsBenefitStatus->load(['employee', 'benefitType']));
    }

    public function update(Request $request, EmployeeSsBenefitStatus $employeeSsBenefitStatus): JsonResponse
    {
        $validated = $request->validate([
            'is_receiving_benefit' => ['sometimes', 'boolean'],
            'ss_benefit_type_id' => ['nullable', 'integer', 'exists:ss_benefit_type,id'],
            'effective_from' => ['sometimes', 'date'],
            'effective_to' => ['nullable', 'date'],
            'verified_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        if (
            isset($validated['effective_from'], $validated['effective_to'])
            && $validated['effective_to'] < $validated['effective_from']
        ) {
            return response()->json([
                'errors' => ['effective_to' => ['End date must be on or after start date']],
            ], 422);
        }

        $employeeSsBenefitStatus->update($validated);

        return response()->json([
            'message' => 'SS benefit status updated successfully',
            'data' => $employeeSsBenefitStatus->fresh()->load(['employee', 'benefitType']),
        ]);
    }

    public function destroy(EmployeeSsBenefitStatus $employeeSsBenefitStatus): JsonResponse
    {
        $employeeSsBenefitStatus->delete();

        return response()->json(['message' => 'SS benefit status deleted successfully']);
    }

    public function preview(Employee $employee, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'as_of' => ['nullable', 'date'],
            'weekly_insurable_earnings' => ['nullable', 'numeric', 'min:0'],
        ]);

        $service = app(\App\Services\SocialSecurity\SocialSecurityContributionService::class);
        $asOf = isset($validated['as_of']) ? \Carbon\Carbon::parse($validated['as_of']) : now();
        $weekly = (float) ($validated['weekly_insurable_earnings'] ?? 520);

        return response()->json($service->previewForEmployee($employee, $asOf, $weekly));
    }
}
