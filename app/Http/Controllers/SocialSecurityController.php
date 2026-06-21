<?php

namespace App\Http\Controllers;

use App\Models\SocialSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SocialSecurityController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = SocialSecurity::query();

        // Filter by state
        if ($request->has('state')) {
            $query->where('state', $request->input('state'));
        }

        // Search by earnings range
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->whereRaw('CAST("weeklyEarningsStartRange" AS TEXT) ILIKE ?', ["%{$search}%"])
                  ->orWhereRaw('CAST("weeklyEarningsEndRange" AS TEXT) ILIKE ?', ["%{$search}%"]);
            });
        }

        // Sorting
        $sortField = $request->get('sort_by', 'weeklyEarningsStartRange');
        $sortDirection = $request->get('sort_direction', 'asc');
        
        $allowedSortFields = [
            'weeklyEarningsStartRange',
            'weeklyEarningsEndRange',
            'weeklyInsurableEarnings',
            'weeklyEmployeeContributions',
            'weeklyEmployerContributions',
            'state',
            'created_at',
            'updated_at'
        ];

        if (in_array($sortField, $allowedSortFields)) {
            $query->orderBy($sortField, $sortDirection);
        } else {
            $query->orderBy('weeklyEarningsStartRange', 'asc');
        }

        // Pagination
        $perPage = $request->get('per_page', 10);
        $socialSecurities = $query->paginate($perPage);

        return response()->json($socialSecurities);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'weeklyEarningsStartRange' => 'required|numeric|min:0',
                'weeklyEarningsEndRange' => 'required|numeric|min:0|gte:weeklyEarningsStartRange',
                'weeklyInsurableEarnings' => 'required|numeric|min:0',
                'weeklyEmployeeContributions' => 'required|numeric|min:0',
                'weeklyEmployerContributions' => 'required|numeric|min:0',
                'weekyEmployeeContributionsRate' => 'required|numeric|min:0|max:100',
                'weeklyEmployerContributionsRate' => 'required|numeric|min:0|max:100',
                'maxWeeklyShortTermBenefit' => 'required|numeric|min:0',
                'maxWeeklyPensions' => 'required|numeric|min:0',
                'maxYearlyPension' => 'required|numeric|min:0',
                'state' => 'nullable|in:active,inactive',
            ]);

            $socialSecurity = SocialSecurity::create($validatedData);
            return response()->json([
                'message' => 'Social security record created successfully',
                'data' => $socialSecurity
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): JsonResponse
    {
        $socialSecurity = SocialSecurity::findOrFail($id);
        return response()->json($socialSecurity, 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $socialSecurity = SocialSecurity::findOrFail($id);

            // If request is empty, return early with current data
            if (empty($request->all())) {
                return response()->json([
                    'message' => 'No data provided for update',
                    'data' => $socialSecurity
                ], 200);
            }

            $validatedData = $request->validate([
                'weeklyEarningsStartRange' => 'sometimes|numeric|min:0',
                'weeklyEarningsEndRange' => 'sometimes|numeric|min:0',
                'weeklyInsurableEarnings' => 'sometimes|numeric|min:0',
                'weeklyEmployeeContributions' => 'sometimes|numeric|min:0',
                'weeklyEmployerContributions' => 'sometimes|numeric|min:0',
                'weekyEmployeeContributionsRate' => 'sometimes|numeric|min:0|max:100',
                'weeklyEmployerContributionsRate' => 'sometimes|numeric|min:0|max:100',
                'maxWeeklyShortTermBenefit' => 'sometimes|numeric|min:0',
                'maxWeeklyPensions' => 'sometimes|numeric|min:0',
                'maxYearlyPension' => 'sometimes|numeric|min:0',
                'state' => 'sometimes|in:active,inactive',
            ]);

            // Validate that end range is greater than or equal to start range if both are provided
            if ($request->has('weeklyEarningsStartRange') || $request->has('weeklyEarningsEndRange')) {
                $startRange = $validatedData['weeklyEarningsStartRange'] ?? $socialSecurity->weeklyEarningsStartRange;
                $endRange = $validatedData['weeklyEarningsEndRange'] ?? $socialSecurity->weeklyEarningsEndRange;
                
                if ($endRange < $startRange) {
                    return response()->json([
                        'errors' => ['weeklyEarningsEndRange' => ['End range must be greater than or equal to start range']]
                    ], 422);
                }
            }

            $socialSecurity->update($validatedData);
            return response()->json([
                'message' => 'Social security record updated successfully',
                'data' => $socialSecurity
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        $socialSecurity = SocialSecurity::findOrFail($id);
        $socialSecurity->delete();
        return response()->json(['message' => 'Social security record deleted successfully.'], 200);
    }
}

