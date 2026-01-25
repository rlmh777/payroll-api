<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CompanyController extends Controller
{
    /**
     * Display a listing of companies.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Company::with([
            'locality:id,name',
        ]);

        // Search (legal name, alias, email, phone)
        if ($request->has('search')) {
            $search = $request->input('search');

            $query->where(function ($q) use ($search) {
                $q->where('legalName', 'ilike', "%{$search}%")
                    ->orWhere('alias', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhere('phoneNumber1', 'ilike', "%{$search}%")
                    ->orWhere('phoneNumber2', 'ilike', "%{$search}%");
            });
        }

        // Filter by locality
        if ($request->filled('localityId')) {
            $query->where('localityId', $request->localityId);
        }

        // Sorting
        $sortField = $request->get('sort_by', 'legalName');
        $sortDirection = $request->get('sort_direction', 'asc');

        if (
            in_array($sortField, [
                'legalName',
                'alias',
                'email',
                'created_at'
            ])
        ) {
            $query->orderBy($sortField, $sortDirection);
        } else {
            $query->orderBy('legalName', 'asc');
        }

        // Pagination
        $perPage = $request->get('per_page', 10);
        $companies = $query->paginate($perPage);

        return response()->json($companies);
    }

    /**
     * Store a newly created company.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'legalName' => 'required|string|max:255',
                'alias' => 'nullable|string|max:255',
                'socialSecurityNumber' => 'nullable|string|max:50',
                'taxIdentificationNumber' => 'nullable|string|max:50',
                'logoPath' => 'nullable|string|max:255',
                'phoneNumber1' => 'nullable|string|max:50',
                'phoneNumber2' => 'nullable|string|max:50',
                'email' => 'nullable|email|max:255',
                'street' => 'nullable|string|max:255',
                'localityId' => 'required|exists:locality,id',
            ]);

            $company = Company::create($validatedData);

            return response()->json([
                'message' => 'Company created successfully',
                'data' => $company
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'error' => $e->errors()
            ], 422);
        }
    }

    /**
     * Display the specified company.
     */
    public function show(Company $company): JsonResponse
    {
        $company->load([
            'locality:id,name',
            'companyBankAccounts'
        ]);

        return response()->json($company, 200);
    }

    /**
     * Update the specified company.
     */
    public function update(Request $request, Company $company): JsonResponse
    {
        try {
            // If request is empty, return early
            if (empty($request->all())) {
                return response()->json([
                    'message' => 'No data provided for update',
                    'data' => $company
                ], 200);
            }

            $validatedData = $request->validate([
                'legalName' => 'sometimes|string|max:255',
                'alias' => 'sometimes|nullable|string|max:255',
                'socialSecurityNumber' => 'sometimes|nullable|string|max:50',
                'taxIdentificationNumber' => 'sometimes|nullable|numeric',
                'logoPath' => 'sometimes|nullable|string|max:255',
                'phoneNumber1' => 'sometimes|nullable|string|max:50',
                'phoneNumber2' => 'sometimes|nullable|string|max:50',
                'email' => 'sometimes|nullable|email|max:255',
                'street' => 'sometimes|nullable|string|max:255',
                'localityId' => 'sometimes|exists:locality,id',
            ]);

            $company->update($validatedData);

            return response()->json([
                'message' => 'Company updated successfully',
                'data' => $company
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'error' => $e->errors()
            ], 422);
        }
    }

    /**
     * Remove the specified company.
     */
    public function destroy(Company $company): JsonResponse
    {
        // Prevent deletion if bank accounts exist
        if ($company->companyBankAccounts()->exists()) {
            return response()->json([
                'error' => 'Cannot delete company with associated bank accounts'
            ], 422);
        }

        $company->delete();

        return response()->json([
            'message' => 'Company deleted successfully'
        ]);
    }
}
