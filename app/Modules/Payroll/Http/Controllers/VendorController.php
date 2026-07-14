<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class VendorController extends Controller
{
    /**
     * Display a listing of vendors.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Vendor::query();

        // Search by name
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('name', 'ilike', "%{$search}%");
        }

        // Filter by bankId
        if ($request->has('bankId')) {
            $query->where('bankId', $request->input('bankId'));
        }

        // Sorting
        $sortField = $request->get('sort_by', 'name');
        $sortDirection = $request->get('sort_direction', 'asc');
        
        if (in_array($sortField, ['name', 'phone', 'email', 'bankId', 'accountNumber'])) {
            $query->orderBy($sortField, $sortDirection);
        } else {
            $query->orderBy('name', 'asc');
        }

        // Pagination
        $perPage = $request->get('per_page', 10);
        $vendors = $query->paginate($perPage);

        return response()->json($vendors);
    }

    /**
     * Store a newly created vendor.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'phone' => 'nullable|string|max:255',
                'email' => 'nullable|email|max:255',
                'bankId' => 'nullable|uuid|exists:bank,id',
                'accountNumber' => 'nullable|string|max:255',
            ]);

            $vendor = Vendor::create($validatedData);
            return response()->json([
                'message' => 'Vendor created successfully',
                'data' => $vendor->load('bank')
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    /**
     * Display the specified vendor.
     */
    public function show(Vendor $vendor): JsonResponse
    {
        return response()->json($vendor->load('bank'), 200);
    }

    /**
     * Update the specified vendor.
     */
    public function update(Request $request, Vendor $vendor): JsonResponse
    {
        try {
            // If request is empty, return early with current data
            if (empty($request->all())) {
                return response()->json([
                    'message' => 'No data provided for update',
                    'data' => $vendor->load('bank')
                ], 200);
            }

            $validatedData = $request->validate([
                'name' => 'sometimes|string|max:255',
                'phone' => 'sometimes|nullable|string|max:255',
                'email' => 'sometimes|nullable|email|max:255',
                'bankId' => 'sometimes|nullable|uuid|exists:bank,id',
                'accountNumber' => 'sometimes|nullable|string|max:255',
            ]);

            $vendor->update($validatedData);
            return response()->json([
                'message' => 'Vendor updated successfully',
                'data' => $vendor->load('bank')
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    /**
     * Remove the specified vendor.
     */
    public function destroy(Vendor $vendor): JsonResponse
    {
        // Check if vendor has associated deductions
        if ($vendor->deductions()->count() > 0 || $vendor->historicalDeductions()->count() > 0) {
            return response()->json([
                'error' => 'Cannot delete vendor with associated deductions'
            ], 422);
        }

        $vendor->delete();
        return response()->json(['message' => 'Vendor deleted successfully.'], 200);
    }
}

