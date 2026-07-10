<?php

namespace App\Http\Controllers;

use App\Models\BankAccountType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BankAccountTypeController extends Controller
{
    /**
     * Display a listing of bank account types.
     */
    public function index(Request $request): JsonResponse
    {
        $query = BankAccountType::query();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('name', 'ilike', "%{$search}%");
        }

        $sortBy = $request->input('sort_by', 'name');
        $sortDirection = $request->input('sort_direction', 'asc');

        if (in_array($sortBy, ['name'], true)) {
            $query->orderBy($sortBy, $sortDirection);
        } else {
            $query->orderBy('name', 'asc');
        }

        $bankAccountTypes = $query->paginate((int) $request->input('per_page', 10));

        return response()->json($bankAccountTypes);
    }

    /**
     * Store a newly created bank account type.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:128|unique:bank_account_type,name',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $bankAccountType = BankAccountType::create($validator->validated());

        return response()->json([
            'message' => 'Bank account type created successfully',
            'data' => $bankAccountType,
        ], 201);
    }

    /**
     * Display the specified bank account type.
     */
    public function show(BankAccountType $bankAccountType): JsonResponse
    {
        return response()->json($bankAccountType);
    }

    /**
     * Update the specified bank account type.
     */
    public function update(Request $request, BankAccountType $bankAccountType): JsonResponse
    {
        if ($request->isMethod('put') && empty($request->all())) {
            return response()->json(['message' => 'No data provided for update'], 422);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:128|unique:bank_account_type,name,' . $bankAccountType->id,
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $bankAccountType->update($validator->validated());

        return response()->json([
            'message' => 'Bank account type updated successfully',
            'data' => $bankAccountType,
        ]);
    }

    /**
     * Remove the specified bank account type.
     */
    public function destroy(BankAccountType $bankAccountType): JsonResponse
    {
        if ($bankAccountType->companyBankAccounts()->exists()) {
            return response()->json([
                'message' => 'Cannot delete bank account type with associated company bank accounts',
            ], 422);
        }

        $bankAccountType->delete();

        return response()->json([
            'message' => 'Bank account type deleted successfully',
        ]);
    }
}
