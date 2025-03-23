<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ChartOfAccountController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ChartOfAccount::query();

        // Search by code or name
        if ($request->has('search')) {
            $searchTerm = strtolower($request->search);
            $query->where(function($q) use ($searchTerm) {
                $q->whereRaw('LOWER(code) LIKE ?', ['%' . $searchTerm . '%'])
                  ->orWhereRaw('LOWER(name) LIKE ?', ['%' . $searchTerm . '%']);
            });
        }

        // Filter by account type
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Filter by parent account
        if ($request->has('parent_id')) {
            $query->where('parent_id', $request->parent_id);
        }

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Sorting
        $sortField = $request->get('sort_by', 'code');
        $sortDirection = $request->get('sort_direction', 'asc');
        
        if (in_array($sortField, ['code', 'name', 'type', 'balance'])) {
            $query->orderBy($sortField, $sortDirection);
        } else {
            $query->orderBy('code', 'asc');
        }

        // Pagination
        $perPage = $request->get('per_page', 10);
        $accounts = $query->paginate($perPage);

        return response()->json($accounts);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'code' => 'required|string|max:20|unique:chart_of_accounts,code',
                'name' => 'required|string|max:255',
                'type' => 'required|string|in:asset,liability,equity,revenue,expense',
                'parent_id' => 'nullable|exists:chart_of_accounts,id',
                'description' => 'nullable|string|max:1024',
                'is_active' => 'boolean',
                'balance' => 'required|numeric|min:0',
                'level' => 'required|integer|min:1|max:10',
            ]);

            $account = ChartOfAccount::create($validatedData);
            return response()->json([
                'message' => 'Chart of Account created successfully',
                'data' => $account
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(ChartOfAccount $chartOfAccount): JsonResponse
    {
        return response()->json($chartOfAccount, 200);
    }

    /**
     * Update the specified resource in storage.
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
                'code' => 'sometimes|string|max:20|unique:chart_of_accounts,code,' . $chartOfAccount->id,
                'name' => 'sometimes|string|max:255',
                'type' => 'sometimes|string|in:asset,liability,equity,revenue,expense',
                'parent_id' => 'sometimes|nullable|exists:chart_of_accounts,id',
                'description' => 'sometimes|nullable|string|max:1024',
                'is_active' => 'sometimes|boolean',
                'balance' => 'sometimes|numeric|min:0',
                'level' => 'sometimes|integer|min:1|max:10',
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
     * Remove the specified resource from storage.
     */
    public function destroy(ChartOfAccount $chartOfAccount): JsonResponse
    {
        // Check if account has children
        if ($chartOfAccount->children()->exists()) {
            return response()->json([
                'error' => 'Cannot delete account with child accounts'
            ], 422);
        }

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