<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Enums\PayrollItemOccurrence;
use App\Models\EmployeeDefaultDeduction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EmployeeDefaultDeductionController extends Controller
{
    private const RELATIONS = [
        'employee',
        'deduction.account',
        'deductionType.account',
        'bank',
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

        if ($request->filled('occurrence')) {
            $query->where('occurrence', $request->input('occurrence'));
        }

        if ($request->filled('account_id')) {
            $accountId = $request->input('account_id');
            $query->where(function ($accountQuery) use ($accountId) {
                $accountQuery
                    ->where('accountId', $accountId)
                    ->orWhereHas('deductionType', function ($typeQuery) use ($accountId) {
                        $typeQuery->where('accountId', $accountId);
                    });
            });
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
            'bankId' => ['nullable', 'uuid', 'exists:bank,id'],
            'accountNumber' => ['nullable', 'string', 'max:255'],
            ...PayrollItemOccurrence::assignmentRules(),
            'accountId' => ['nullable', 'uuid', 'exists:accounts,id'],
            'amount' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
            'allowPartialDeduction' => ['sometimes', 'boolean'],
            'applicationRule' => ['nullable', 'string', 'max:255'],
            'priority' => ['sometimes', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = PayrollItemOccurrence::normalizeAssignment($validator->validated());
        if (($data['accountNumber'] ?? null) === '') {
            $data['accountNumber'] = null;
        }
        if (($data['bankId'] ?? null) === '') {
            $data['bankId'] = null;
        }

        $exists = EmployeeDefaultDeduction::where('employeeId', $request->employeeId)
            ->where('deductionTypeId', $request->deductionTypeId)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'This deduction is already assigned to the employee',
            ], 422);
        }

        $defaultDeduction = EmployeeDefaultDeduction::create($data);

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
            'bankId' => ['sometimes', 'nullable', 'uuid', 'exists:bank,id'],
            'accountNumber' => ['sometimes', 'nullable', 'string', 'max:255'],
            ...PayrollItemOccurrence::assignmentRules(false),
            'accountId' => ['sometimes', 'nullable', 'uuid', 'exists:accounts,id'],
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
            'allowPartialDeduction' => ['sometimes', 'boolean'],
            'applicationRule' => ['nullable', 'string', 'max:255'],
            'priority' => ['sometimes', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = PayrollItemOccurrence::normalizeAssignment($validator->validated());
        if (array_key_exists('accountNumber', $data) && $data['accountNumber'] === '') {
            $data['accountNumber'] = null;
        }
        if (array_key_exists('bankId', $data) && $data['bankId'] === '') {
            $data['bankId'] = null;
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

        $defaultDeduction->update($data);

        return response()->json($defaultDeduction->load(self::RELATIONS));
    }

    public function destroy(EmployeeDefaultDeduction $defaultDeduction)
    {
        $defaultDeduction->delete();

        return response()->json(null, 204);
    }
}
