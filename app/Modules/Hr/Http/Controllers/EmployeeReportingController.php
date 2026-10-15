<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Models\EmployeeReporting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EmployeeReportingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = EmployeeReporting::query()->with(['supervisor', 'subordinate']);

        if ($request->filled('supervisor_id')) {
            $query->where('supervisor_id', $request->string('supervisor_id'));
        }

        if ($request->filled('subordinate_id')) {
            $query->where('subordinate_id', $request->string('subordinate_id'));
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return response()->json($query->orderByDesc('created_at')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supervisor_id' => ['required', 'uuid', 'exists:employee,id'],
            'subordinate_id' => ['required', 'uuid', 'exists:employee,id'],
            'reporting_method' => ['required', 'string', 'max:32'],
            'effective_date' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $reporting = EmployeeReporting::create([
            'id' => (string) Str::uuid(),
            'supervisor_id' => $validated['supervisor_id'],
            'subordinate_id' => $validated['subordinate_id'],
            'reporting_method' => $validated['reporting_method'],
            'effective_date' => $validated['effective_date'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json($reporting->load(['supervisor', 'subordinate']), 201);
    }

    public function destroy(EmployeeReporting $employeeReporting): JsonResponse
    {
        $employeeReporting->delete();

        return response()->json(['message' => 'Reporting relationship removed']);
    }
}
