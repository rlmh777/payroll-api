<?php

namespace App\Http\Controllers;

use App\Models\EmployeeDefaultDeduction;
use App\Models\Employee;
use App\Models\Deduction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class EmployeeDefaultDeductionController extends Controller
{
    public function index(Request $request)
    {
        $query = EmployeeDefaultDeduction::with(['employee', 'deduction']);

        // Filter by employee
        if ($request->has('employeeId')) {
            $query->where('employeeId', $request->input('employeeId'));
        }

        // Filter by deduction
        if ($request->has('deductionId')) {
            $query->where('deductionId', $request->input('deductionId'));
        }

        // Filter by active status
        if ($request->has('isActive')) {
            $query->where('isActive', $request->boolean('isActive'));
        }

        // Sort
        if ($request->has('sortBy')) {
            $sortDirection = $request->input('sortDirection', 'asc');
            $query->orderBy($request->input('sortBy'), $sortDirection);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        return $query->paginate($request->input('per_page', 15));
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employeeId' => ['required', 'uuid', 'exists:employee,id'],
            'deductionTypeId' => ['required', 'numeric', 'exists:deduction_type,id'],
            'paymentToId' => ['required', 'uuid', 'exists:vendor,id'],
            'frequencyId' => ['required', 'numeric', 'exists:payrate_frequency,id'],
            'accountId' => ['required', 'uuid', 'exists:accounts,id'],
            'amount' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Check if the deduction is already assigned to the employee
        $exists = EmployeeDefaultDeduction::where('employeeId', $request->employeeId)
            ->where('deductionTypeId', $request->deductionTypeId)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'This deduction is already assigned to the employee'
            ], 422);
        }

        $defaultDeduction = EmployeeDefaultDeduction::create($request->all());

        return response()->json($defaultDeduction->load(['employee', 'deduction']), 201);
    }

    public function show(EmployeeDefaultDeduction $defaultDeduction)
    {
        return $defaultDeduction->load(['employee', 'deduction']);
    }

    public function update(Request $request, EmployeeDefaultDeduction $defaultDeduction)
    {
        if ($request->isMethod('put') && empty($request->all())) {
            return response()->json([
                'message' => 'No data provided for update'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'employeeId' => ['sometimes', 'uuid', 'exists:employees,id'],
            'deductionId' => ['sometimes', 'uuid', 'exists:deductions,id'],
            'paymentToId' => ['sometimes', 'uuid', 'exists:vendor,id'],
            'frequencyId' => ['sometimes', 'uuid', 'exists:payrateFrequency,id'],
            'accountId' => ['sometimes', 'uuid', 'exists:accounts,id'],
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // If employee or deduction is being changed, check for duplicates
        if ($request->has('employeeId') || $request->has('deductionId')) {
            $employeeId = $request->input('employeeId', $defaultDeduction->employeeId);
            $deductionId = $request->input('deductionId', $defaultDeduction->deductionId);

            $exists = EmployeeDefaultDeduction::where('id', '!=', $defaultDeduction->id)
                ->where('employeeId', $employeeId)
                ->where('deductionId', $deductionId)
                ->exists();

            if ($exists) {
                return response()->json([
                    'message' => 'This deduction is already assigned to the employee'
                ], 422);
            }
        }

        $defaultDeduction->update($request->all());

        return response()->json($defaultDeduction->load(['employee', 'deduction']));
    }

    public function destroy(EmployeeDefaultDeduction $defaultDeduction)
    {
        $defaultDeduction->delete();
        return response()->json(null, 204);
    }
}