<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CompanyController extends Controller
{
    /**
     * Return the single company record (paginated wrapper for frontend compatibility).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Company::with('locality');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('legalName', 'ilike', "%{$search}%")
                    ->orWhere('alias', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        $company = $query->first();
        $perPage = max((int) $request->get('per_page', 10), 1);

        return response()->json([
            'data' => $company ? [$company] : [],
            'current_page' => 1,
            'last_page' => 1,
            'per_page' => $perPage,
            'total' => $company ? 1 : 0,
        ]);
    }

    /**
     * Display the company record.
     */
    public function show(Company $company): JsonResponse
    {
        return response()->json($company->load('locality'));
    }

    /**
     * Update the company record. Creation is not allowed via the API.
     */
    public function update(Request $request, Company $company): JsonResponse
    {
        try {
            $validated = $request->validate([
                'legalName' => 'sometimes|required|string|max:255',
                'alias' => 'nullable|string|max:255',
                'socialSecurityNumber' => 'nullable|string|max:255',
                'taxIdentificationNumber' => 'nullable|string|max:255',
                'logoPath' => 'nullable|string|max:255',
                'phoneNumber1' => 'sometimes|required|string|max:255',
                'phoneNumber2' => 'nullable|string|max:255',
                'email' => 'sometimes|required|email|max:255',
                'street' => 'nullable|string|max:255',
                'localityId' => 'sometimes|required|uuid|exists:locality,id',
                'primaryColor' => ['nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
                'secondaryColor' => ['nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            ]);

            $company->update($validated);

            return response()->json([
                'message' => 'Company updated successfully',
                'data' => $company->fresh('locality'),
            ]);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }
}
