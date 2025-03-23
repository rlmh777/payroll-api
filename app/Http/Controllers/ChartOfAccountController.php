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

        // Search by name, description, or codes
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('code1', 'like', "%{$search}%")
                  ->orWhere('code2', 'like', "%{$search}%");
            });
        }

        // Filter by type
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

        // Filter by balance range
        if ($request->has('min_balance')) {
            $query->where('balance', '>=', $request->min_balance);
        }
        if ($request->has('max_balance')) {
            $query->where('balance', '<=', $request->max_balance);
        }

        // Sorting
        $sortField = $request->get('sort_by', 'name');
        $sortDirection = $request->get('sort_direction', 'asc');
        
        if (in_array($sortField, ['name', 'code1', 'code2', 'type', 'balance', 'level'])) {
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
                'parent_id' => 'nullable|exists:chart_of_account,id',
                'type' => 'required|string|in:asset,liability,equity,revenue,expense',
                'is_active' => 'boolean',
                'balance' => 'numeric|min:0',
                'level' => 'integer|min:1|max:10'
            ]);

            // Calculate level based on parent
            if (isset($validatedData['parent_id'])) {
                $parent = ChartOfAccount::findOrFail($validatedData['parent_id']);
                $validatedData['level'] = $parent->level + 1;
            } else {
                $validatedData['level'] = 1;
            }

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
                'parent_id' => 'sometimes|nullable|exists:chart_of_account,id',
                'type' => 'sometimes|string|in:asset,liability,equity,revenue,expense',
                'is_active' => 'sometimes|boolean',
                'balance' => 'sometimes|numeric|min:0',
                'level' => 'sometimes|integer|min:1|max:10'
            ]);

            // Prevent circular reference
            if (isset($validatedData['parent_id']) && $validatedData['parent_id'] == $chartOfAccount->id) {
                return response()->json([
                    'error' => 'An account cannot be its own parent'
                ], 422);
            }

            // Update level if parent changes
            if (isset($validatedData['parent_id'])) {
                $parent = ChartOfAccount::findOrFail($validatedData['parent_id']);
                $validatedData['level'] = $parent->level + 1;
            }

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
        // Check if account has children
        if ($chartOfAccount->children()->exists()) {
            return response()->json([
                'error' => 'Cannot delete account with child accounts'
            ], 422);
        }

        // Check if account has transactions
        if ($chartOfAccount->transactions()->exists()) {
            return response()->json([
                'error' => 'Cannot delete account with associated transactions'
            ], 422);
        }

        $chartOfAccount->delete();
        return response()->json(['message' => 'Chart of Account deleted successfully.']);
    }

    /**
     * Get the account hierarchy.
     */
    public function hierarchy(ChartOfAccount $chartOfAccount): JsonResponse
    {
        $hierarchy = $chartOfAccount->load(['children' => function ($query) {
            $query->orderBy('name');
        }]);
        return response()->json($hierarchy);
    }

    /**
     * Get the account balance history.
     */
    public function balanceHistory(ChartOfAccount $chartOfAccount): JsonResponse
    {
        $history = $chartOfAccount->transactions()
            ->select('created_at', 'amount')
            ->orderBy('created_at')
            ->get();
        return response()->json($history);
    }
} 