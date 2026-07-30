<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\EmployeeBank;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EmployeeBankController extends Controller
{
    public function index(Request $request)
    {
        $query = EmployeeBank::with(['employee', 'bank']);

        if ($request->filled('employee_id')) {
            $query->where('employeeId', $request->input('employee_id'));
        }

        if ($request->filled('employeeId')) {
            $query->where('employeeId', $request->input('employeeId'));
        }

        if ($request->filled('bank_id')) {
            $query->where('bankId', $request->input('bank_id'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($builder) use ($search) {
                $builder->where('accountNumber', 'ilike', "%{$search}%")
                    ->orWhere('notes', 'ilike', "%{$search}%");
            });
        }

        $sortBy = $request->input('sort_by', 'isPrimary');
        $sortDirection = $request->input('sort_direction', 'desc');
        $query->orderBy($sortBy, $sortDirection)
            ->orderBy('created_at', 'desc');

        $perPage = (int) $request->input('per_page', 20);
        $employeeBanks = $query->paginate($perPage);

        return response()->json($employeeBanks);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employeeId' => 'required|uuid|exists:employee,id',
            'bankId' => 'required|uuid|exists:bank,id',
            'accountNumber' => 'required|string|max:255',
            'isPrimary' => 'sometimes|boolean',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $data['isPrimary'] = $this->resolvePrimaryFlag(
            (string) $data['employeeId'],
            $request->boolean('isPrimary'),
            true,
        );

        $employeeBank = EmployeeBank::create($data);

        return response()->json([
            'message' => 'Employee bank created successfully',
            'data' => $employeeBank->load(['employee', 'bank']),
        ], 201);
    }

    public function show(EmployeeBank $employeeBank)
    {
        return response()->json($employeeBank->load(['employee', 'bank']));
    }

    public function update(Request $request, EmployeeBank $employeeBank)
    {
        if ($request->isMethod('put') && empty($request->all())) {
            return response()->json(['message' => 'No data provided for update'], 422);
        }

        $validator = Validator::make($request->all(), [
            'employeeId' => 'uuid|exists:employee,id',
            'bankId' => 'uuid|exists:bank,id',
            'accountNumber' => 'string|max:255',
            'isPrimary' => 'sometimes|boolean',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $employeeId = (string) ($data['employeeId'] ?? $employeeBank->employeeId);

        if (array_key_exists('isPrimary', $data)) {
            $data['isPrimary'] = $this->resolvePrimaryFlag(
                $employeeId,
                (bool) $data['isPrimary'],
                false,
                (string) $employeeBank->id,
            );
        }

        $employeeBank->update($data);

        return response()->json([
            'message' => 'Employee bank updated successfully',
            'data' => $employeeBank->load(['employee', 'bank']),
        ]);
    }

    public function destroy(EmployeeBank $employeeBank)
    {
        $employeeBank->delete();

        return response()->json([
            'message' => 'Employee bank deleted successfully',
        ]);
    }

    private function resolvePrimaryFlag(
        string $employeeId,
        bool $requestedPrimary,
        bool $isCreate,
        ?string $currentId = null,
    ): bool {
        $existingPrimaryQuery = EmployeeBank::query()
            ->where('employeeId', $employeeId)
            ->where('isPrimary', true);

        if ($currentId) {
            $existingPrimaryQuery->where('id', '!=', $currentId);
        }

        $hasOtherPrimary = $existingPrimaryQuery->exists();

        if ($isCreate && !$hasOtherPrimary) {
            return true;
        }

        if ($requestedPrimary) {
            return true;
        }

        if (!$hasOtherPrimary && !$isCreate) {
            return true;
        }

        return false;
    }
}
