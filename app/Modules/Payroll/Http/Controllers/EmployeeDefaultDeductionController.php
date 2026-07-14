<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\EmployeeDefaultDeduction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EmployeeDefaultDeductionController extends Controller
{
    private const RELATIONS = [
        'employee',
        'deduction',
        'deductionType',
        'bank',
        'payrateFrequency',
        'chartOfAccount',
    ];

    public function index(Request $request)
    {
        $query = EmployeeDefaultDeduction::with(self::RELATIONS);

        if ($request->has('employeeId')) {
            $query->where('employeeId', $request->input('employeeId'));
        }

        if ($request->has('deductionTypeId')) {
            $query->where('deductionTypeId', $request->input('deductionTypeId'));
        }

        if ($request->has('deductionId')) {
            $query->where('deductionTypeId', $request->input('deductionId'));
        }

        if ($request->has('frequency_id')) {
            $query->where('frequencyId', $request->input('frequency_id'));
        }

        if ($request->has('account_id')) {
            $query->where('accountId', $request->input('account_id'));
        }

        if ($request->has('sortBy')) {
            $sortDirection = $request->input('sortDirection', 'asc');
            $query->orderBy($request->input('sortBy'), $sortDirection);
        } else {
            $query->orderBy('priority', 'asc')->orderBy('created_at', 'desc');
        }

        return $query->paginate($request->input('per_page', 15));
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employeeId' => ['required', 'uuid', 'exists:employee,id'],
            'deductionTypeId' => ['required', 'numeric', 'exists:deduction_type,id'],
            'bankId' => ['required', 'uuid', 'exists:bank,id'],
            'accountNumber' => ['required', 'string', 'max:255'],
            'frequencyId' => ['required', 'numeric', 'exists:payrate_frequency,id'],
            'accountId' => ['required', 'uuid', 'exists:accounts,id'],
            'amount' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
            'allowPartialDeduction' => ['sometimes', 'boolean'],
            'applicationRule' => ['nullable', 'string', 'max:255'],
            'priority' => ['sometimes', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $exists = EmployeeDefaultDeduction::where('employeeId', $request->employeeId)
            ->where('deductionTypeId', $request->deductionTypeId)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'This deduction is already assigned to the employee',
            ], 422);
        }

        $defaultDeduction = EmployeeDefaultDeduction::create($validator->validated());

        return response()->json($defaultDeduction->load(self::RELATIONS), 201);
    }

    public function show(EmployeeDefaultDeduction $defaultDeduction)
    {
        return $defaultDeduction->load(self::RELATIONS);
    }

    public function update(Request $request, EmployeeDefaultDeduction $defaultDeduction)
    {
        if ($request->isMethod('put') && empty($request->all())) {
            return response()->json([
                'message' => 'No data provided for update',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'employeeId' => ['sometimes', 'uuid', 'exists:employee,id'],
            'deductionTypeId' => ['sometimes', 'numeric', 'exists:deduction_type,id'],
            'bankId' => ['sometimes', 'uuid', 'exists:bank,id'],
            'accountNumber' => ['sometimes', 'string', 'max:255'],
            'frequencyId' => ['sometimes', 'numeric', 'exists:payrate_frequency,id'],
            'accountId' => ['sometimes', 'uuid', 'exists:accounts,id'],
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
            'allowPartialDeduction' => ['sometimes', 'boolean'],
            'applicationRule' => ['nullable', 'string', 'max:255'],
            'priority' => ['sometimes', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($request->has('employeeId') || $request->has('deductionTypeId')) {
            $employeeId = $request->input('employeeId', $defaultDeduction->employeeId);
            $deductionTypeId = $request->input('deductionTypeId', $defaultDeduction->deductionTypeId);

            $exists = EmployeeDefaultDeduction::where('id', '!=', $defaultDeduction->id)
                ->where('employeeId', $employeeId)
                ->where('deductionTypeId', $deductionTypeId)
                ->exists();

            if ($exists) {
                return response()->json([
                    'message' => 'This deduction is already assigned to the employee',
                ], 422);
            }
        }

        $defaultDeduction->update($validator->validated());

        return response()->json($defaultDeduction->load(self::RELATIONS));
    }

    public function destroy(EmployeeDefaultDeduction $defaultDeduction)
    {
        $defaultDeduction->delete();

        return response()->json(null, 204);
    }
}
