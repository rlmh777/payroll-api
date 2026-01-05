<?php

namespace App\Http\Controllers;

use App\Models\AccountType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AccountTypeController extends Controller
{
    /**
     * Display a listing of account types.
     */
    public function index(Request $request): JsonResponse
    {
        $query = AccountType::query();

        // Search by name
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('name', 'ilike', "%{$search}%");
        }

        // Filter by statement type
        if ($request->has('statement')) {
            $query->where('statement', $request->input('statement'));
        }

        // Filter by normal_balance
        if ($request->has('normal_balance')) {
            $query->where('normal_balance', $request->input('normal_balance'));
        }

        // Sorting
        $sortField = $request->get('sort_by', 'name');
        $sortDirection = $request->get('sort_direction', 'asc');

        if (in_array($sortField, ['name', 'normal_balance', 'statement'])) {
            $query->orderBy($sortField, $sortDirection);
        } else {
            $query->orderBy('name', 'asc');
        }

        // Pagination
        $perPage = $request->get('per_page', 10);
        $accountTypes = $query->paginate($perPage);

        return response()->json($accountTypes);
    }

    /**
     * Store a newly created account type.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255|unique:account_types,name',
                'normal_balance' => 'nullable|string|max:255',
                'statement' => 'nullable|in:Balance Sheet,Income Statement',
            ]);

            $accountType = AccountType::create($validatedData);
            return response()->json([
                'message' => 'Account type created successfully',
                'data' => $accountType
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    /**
     * Display the specified account type.
     */
    public function show(AccountType $accountType): JsonResponse
    {
        // Optionally load relationships
        if (request()->boolean('with_relations')) {
            $accountType->load('chartOfAccounts');
        }

        return response()->json($accountType, 200);
    }

    /**
     * Update the specified account type.
     */
    public function update(Request $request, AccountType $accountType): JsonResponse
    {
        try {
            // If request is empty, return early with current data
            if (empty($request->all())) {
                return response()->json([
                    'message' => 'No data provided for update',
                    'data' => $accountType
                ], 200);
            }

            $validatedData = $request->validate([
                'name' => 'sometimes|string|max:255|unique:account_types,name,' . $accountType->id,
                'normal_balance' => 'nullable|string|max:255',
                'statement' => 'nullable|in:Balance Sheet,Income Statement',
            ]);

            $accountType->update($validatedData);
            return response()->json([
                'message' => 'Account type updated successfully',
                'data' => $accountType
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    /**
     * Remove the specified account type.
     */
    public function destroy(AccountType $accountType): JsonResponse
    {
        // Check if account type has associated accounts
        if ($accountType->chartOfAccounts()->exists()) {
            return response()->json([
                'error' => 'Cannot delete account type with associated accounts'
            ], 422);
        }

        $accountType->delete();
        return response()->json(['message' => 'Account type deleted successfully.'], 200);
    }
}

