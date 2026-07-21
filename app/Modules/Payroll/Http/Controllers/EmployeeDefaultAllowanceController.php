<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\EmployeeDefaultAllowance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EmployeeDefaultAllowanceController extends Controller
{
    private const RELATIONS = [
        'employee',
        'allowance',
        'chartOfAccount',
    ];

    public function index(Request $request)
    {
        $query = EmployeeDefaultAllowance::with(self::RELATIONS);

        if ($request->has('employee_id')) {
            $query->where('employeeId', $request->input('employee_id'));
        }

        if ($request->has('allowance_id')) {
            $query->where('allowanceId', $request->input('allowance_id'));
        }

        if ($request->has('account_id')) {
            $query->where('accountId', $request->input('account_id'));
        }

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('note', 'ilike', "%{$search}%");
        }

        $sortBy = $request->input('sort_by', 'created_at');
        $sortDirection = $request->input('sort_direction', 'desc');
        $query->orderBy($sortBy, $sortDirection);

        $perPage = $request->input('per_page', 10);
        $allowances = $query->paginate($perPage);

        return response()->json($allowances);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employeeId' => 'required|uuid|exists:employee,id',
            'allowanceId' => 'required|uuid|exists:allowance,id',
            'accountId' => 'required|uuid|exists:accounts,id',
            'note' => 'nullable|string|max:1024',
            'quantity' => 'required|numeric|min:0.0001|max:999999999.9999',
            'unitAmount' => 'required|numeric|min:0|max:999999999999.99',
            'amount' => 'nullable|numeric|min:0|max:999999999999.99',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $data['quantity'] = round((float) $data['quantity'], 4);
        $data['unitAmount'] = round((float) $data['unitAmount'], 2);
        $data['amount'] = round($data['quantity'] * $data['unitAmount'], 2);

        $allowance = EmployeeDefaultAllowance::create($data);

        return response()->json([
            'message' => 'Employee default allowance created successfully',
            'data' => $allowance->load(self::RELATIONS),
        ], 201);
    }

    public function show(EmployeeDefaultAllowance $employeeDefaultAllowance)
    {
        return response()->json($employeeDefaultAllowance->load(self::RELATIONS));
    }

    public function update(Request $request, EmployeeDefaultAllowance $employeeDefaultAllowance)
    {
        if ($request->isMethod('put') && empty($request->all())) {
            return response()->json(['message' => 'No data provided for update'], 422);
        }

        $validator = Validator::make($request->all(), [
            'employeeId' => 'uuid|exists:employee,id',
            'allowanceId' => 'uuid|exists:allowance,id',
            'accountId' => 'uuid|exists:accounts,id',
            'note' => 'nullable|string|max:1024',
            'quantity' => 'numeric|min:0.0001|max:999999999.9999',
            'unitAmount' => 'numeric|min:0|max:999999999999.99',
            'amount' => 'nullable|numeric|min:0|max:999999999999.99',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        if (array_key_exists('quantity', $data) || array_key_exists('unitAmount', $data)) {
            $quantity = array_key_exists('quantity', $data)
                ? round((float) $data['quantity'], 4)
                : (float) $employeeDefaultAllowance->quantity;
            $unitAmount = array_key_exists('unitAmount', $data)
                ? round((float) $data['unitAmount'], 2)
                : (float) $employeeDefaultAllowance->unitAmount;

            $data['quantity'] = $quantity;
            $data['unitAmount'] = $unitAmount;
            $data['amount'] = round($quantity * $unitAmount, 2);
        }

        $employeeDefaultAllowance->update($data);

        return response()->json([
            'message' => 'Employee default allowance updated successfully',
            'data' => $employeeDefaultAllowance->load(self::RELATIONS),
        ]);
    }

    public function destroy(EmployeeDefaultAllowance $employeeDefaultAllowance)
    {
        $employeeDefaultAllowance->delete();

        return response()->json([
            'message' => 'Employee default allowance deleted successfully',
        ]);
    }
}
