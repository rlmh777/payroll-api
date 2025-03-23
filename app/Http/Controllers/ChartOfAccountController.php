<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ChartOfAccountController extends Controller
{
    /**
     * Display a listing of chart of accounts.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ChartOfAccount::query();

        // Search by name or description
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('code1', 'like', "%{$search}%")
                  ->orWhere('code2', 'like', "%{$search}%");
            });
        }

        // Filter by code1
        if ($request->has('code1')) {
            $query->where('code1', $request->input('code1'));
        }

        // Filter by code2
        if ($request->has('code2')) {
            $query->where('code2', $request->input('code2'));
        }

        // Sorting
        $sortField = $request->get('sort_by', 'name');
        $sortDirection = $request->get('sort_direction', 'asc');
        
        if (in_array($sortField, ['name', 'code1', 'code2'])) {
            $query->orderBy($sortField, $sortDirection);
        } else {
            $query->orderBy('name', 'asc');
        }

        // Pagination
        $perPage = $request->get('per_page', 10);
        $chartOfAccounts = $query->paginate($perPage);

        return response()->json($chartOfAccounts);
    }

    /**
     * Store a newly created chart of account.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:100',
                'description' => 'required|string|max:255',
                'code1' => 'required|string|max:24',
                'code2' => 'required|string|max:24',
            ]);

            $chartOfAccount = ChartOfAccount::create($validatedData);
            return response()->json([
                'message' => 'Chart of Account created successfully',
                'data' => $chartOfAccount
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    /**
     * Display the specified chart of account.
     */
    public function show(ChartOfAccount $chartOfAccount): JsonResponse
    {
        return response()->json($chartOfAccount, 200);
    }

    /**
     * Update the specified chart of account.
     */
    public function update(Request $request, ChartOfAccount $chartOfAccount): JsonResponse
    {
        try {
            // If request is empty, return early with current data
            if ($request->isEmpty()) {
                return response()->json([
                    'message' => 'No data provided for update',
                    'data' => $chartOfAccount
                ], 200);
            }

            $validatedData = $request->validate([
                'name' => 'sometimes|string|max:100',
                'description' => 'sometimes|string|max:255',
                'code1' => 'sometimes|string|max:24',
                'code2' => 'sometimes|string|max:24',
            ]);

            $chartOfAccount->update($validatedData);
            return response()->json([
                'message' => 'Chart of Account updated successfully',
                'data' => $chartOfAccount
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    /**
     * Remove the specified chart of account.
     */
    public function destroy(ChartOfAccount $chartOfAccount): JsonResponse
    {
        $chartOfAccount->delete();
        return response()->json(['message' => 'Chart of Account deleted successfully.']);
    }

    /**
     * Get account hierarchy
     */
    public function hierarchy(ChartOfAccount $chartOfAccount): JsonResponse
    {
        $hierarchy = $chartOfAccount->load('children');
        return response()->json($hierarchy);
    }

    /**
     * Get account balance history
     */
    public function balanceHistory(ChartOfAccount $chartOfAccount): JsonResponse
    {
        // This would typically involve a separate balance_history table
        // For now, returning a placeholder response
        return response()->json([
            'message' => 'Balance history feature to be implemented',
            'account' => $chartOfAccount
        ]);
    }
} 