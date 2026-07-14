<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Models\Worksite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WorksiteController extends Controller
{
    /**
     * Display a listing of worksites.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Worksite::query()->with([
            'locality.district.country:id,name,code1,code2,nationalityName',
        ]);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('name', 'ilike', "%{$search}%")
                    ->orWhere('address1', 'ilike', "%{$search}%")
                    ->orWhere('address2', 'ilike', "%{$search}%");
            });
        }

        if ($request->filled('localityId')) {
            $query->where('localityId', $request->input('localityId'));
        }

        $sortField = $request->get('sort_by', 'name');
        $sortDirection = $request->get('sort_direction', 'asc');

        if (in_array($sortField, ['name', 'address1'], true)) {
            $query->orderBy($sortField, $sortDirection);
        } else {
            $query->orderBy('name', 'asc');
        }

        $perPage = (int) $request->get('per_page', 10);
        $worksites = $query->paginate($perPage);

        return response()->json($worksites);
    }

    /**
     * Store a newly created worksite.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:512',
                'address1' => 'required|string|max:512',
                'address2' => 'nullable|string|max:512',
                'localityId' => 'required|uuid|exists:locality,id',
            ]);

            $worksite = Worksite::create($validatedData);

            return response()->json([
                'message' => 'Work site created successfully',
                'data' => $worksite->load([
                    'locality.district.country:id,name,code1,code2,nationalityName',
                ]),
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
    }

    /**
     * Display the specified worksite.
     */
    public function show(Worksite $worksite): JsonResponse
    {
        return response()->json(
            $worksite->load([
                'locality.district.country:id,name,code1,code2,nationalityName',
            ]),
            200,
        );
    }

    /**
     * Update the specified worksite.
     */
    public function update(Request $request, Worksite $worksite): JsonResponse
    {
        try {
            if (empty($request->all())) {
                return response()->json([
                    'message' => 'No data provided for update',
                    'data' => $worksite,
                ], 200);
            }

            $validatedData = $request->validate([
                'name' => 'sometimes|string|max:512',
                'address1' => 'sometimes|string|max:512',
                'address2' => 'sometimes|nullable|string|max:512',
                'localityId' => 'sometimes|uuid|exists:locality,id',
            ]);

            $worksite->update($validatedData);

            return response()->json([
                'message' => 'Work site updated successfully',
                'data' => $worksite->load([
                    'locality.district.country:id,name,code1,code2,nationalityName',
                ]),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
    }

    /**
     * Remove the specified worksite.
     */
    public function destroy(Worksite $worksite): JsonResponse
    {
        if ($worksite->employmentDetails()->exists()) {
            return response()->json([
                'error' => 'Cannot delete work site with associated employment details',
            ], 422);
        }

        $worksite->delete();

        return response()->json([
            'message' => 'Work site deleted successfully',
        ]);
    }
}
