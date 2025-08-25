<?php

namespace App\Http\Controllers;

use App\Models\CompanyBankAccount;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class CompanyBankAccountController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $companyBankAccounts = CompanyBankAccount::with(['company', 'bank', 'accountType'])->get();
        
        return response()->json([
            'success' => true,
            'data' => $companyBankAccounts
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'companyId' => 'required|exists:company,id',
            'bankId' => 'required|uuid|exists:bank,id',
            'accountNumber' => 'required|string|max:255',
            'accountTypeId' => 'required|exists:bank_account_type,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $companyBankAccount = CompanyBankAccount::create($request->all());

        return response()->json([
            'success' => true,
            'data' => $companyBankAccount->load(['company', 'bank', 'accountType']),
            'message' => 'Company bank account created successfully'
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): JsonResponse
    {
        $companyBankAccount = CompanyBankAccount::with(['company', 'bank', 'accountType'])->find($id);

        if (!$companyBankAccount) {
            return response()->json([
                'success' => false,
                'message' => 'Company bank account not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $companyBankAccount
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $companyBankAccount = CompanyBankAccount::find($id);

        if (!$companyBankAccount) {
            return response()->json([
                'success' => false,
                'message' => 'Company bank account not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'companyId' => 'sometimes|required|exists:company,id',
            'bankId' => 'sometimes|required|uuid|exists:bank,id',
            'accountNumber' => 'sometimes|required|string|max:255',
            'accountTypeId' => 'sometimes|required|exists:bank_account_type,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $companyBankAccount->update($request->all());

        return response()->json([
            'success' => true,
            'data' => $companyBankAccount->load(['company', 'bank', 'accountType']),
            'message' => 'Company bank account updated successfully'
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        $companyBankAccount = CompanyBankAccount::find($id);

        if (!$companyBankAccount) {
            return response()->json([
                'success' => false,
                'message' => 'Company bank account not found'
            ], 404);
        }

        $companyBankAccount->delete();

        return response()->json([
            'success' => true,
            'message' => 'Company bank account deleted successfully'
        ]);
    }
} 