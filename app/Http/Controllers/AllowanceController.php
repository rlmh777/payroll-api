<?php

namespace App\Http\Controllers;

use App\Models\Allowance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AllowanceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Allowance::query();

        // Search by name
        if ($request->has('search')) {
            $query->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($request->search) . '%']);
        }

        // Filter by isTaxable
        if ($request->has('isTaxable')) {
            $query->where('isTaxable', $request->boolean('isTaxable'));
        }

        // Filter by isSocialSecurityDeductable
        if ($request->has('isSocialSecurityDeductable')) {
            $query->where('isSocialSecurityDeductable', $request->boolean('isSocialSecurityDeductable'));
        }

        // Filter by defaultAmount range
        if ($request->has('min_amount')) {
            $query->where('defaultAmount', '>=', $request->min_amount);
        }
        if ($request->has('max_amount')) {
            $query->where('defaultAmount', '<=', $request->max_amount);
        }

        // Sorting
        $sortField = $request->get('sort_by', 'name');
        $sortDirection = $request->get('sort_direction', 'asc');
        
        if (in_array($sortField, ['name', 'isTaxable', 'isSocialSecurityDeductable', 'defaultAmount'])) {
            $query->orderBy($sortField, $sortDirection);
        } else {
            $query->orderBy('name', 'asc');
        }

        // Pagination
        $perPage = $request->get('per_page', 10);
        $allowances = $query->paginate($perPage);

        return response()->json($allowances);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'isTaxable' => 'nullable|boolean',
                'isSocialSecurityDeductable' => 'nullable|boolean',
                'note' => 'nullable|string|max:1024',
                'defaultAmount' => 'required|numeric|min:0|max:9999999999.99',
            ]);

            $allowance = Allowance::create($validatedData);
            return response()->json([
                'message' => 'Allowance created successfully',
                'data' => $allowance
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Allowance $allowance): JsonResponse
    {
        return response()->json($allowance, 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Allowance $allowance): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'name' => 'string|max:255',
                'isTaxable' => 'nullable|boolean',
                'isSocialSecurityDeductable' => 'nullable|boolean',
                'note' => 'nullable|string|max:1024',
                'defaultAmount' => 'numeric|min:0|max:9999999999.99',
            ]);

            $allowance->update($validatedData);
            return response()->json([
                'message' => 'Allowance updated successfully',
                'data' => $allowance
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Allowance $allowance): JsonResponse
    {
        $allowance->delete();
        return response()->json(['message' => 'Allowance deleted successfully.']);
    }
}
