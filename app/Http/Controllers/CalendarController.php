<?php

namespace App\Http\Controllers;

use App\Models\Calendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CalendarController extends Controller
{
    /**
     * Display a listing of calendar entries.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Calendar::query();

        if ($request->filled('start')) {
            $query->whereDate('date', '>=', $request->string('start'));
        }

        if ($request->filled('end')) {
            $query->whereDate('date', '<=', $request->string('end'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('description', 'ilike', "%{$search}%");
        }

        $sortField = $request->get('sort_by', 'date');
        $sortDirection = $request->get('sort_direction', 'asc');

        if (in_array($sortField, ['date', 'description', 'multiplier'])) {
            $query->orderBy($sortField, $sortDirection);
        } else {
            $query->orderBy('date', 'asc');
        }

        $perPage = $request->get('per_page', 50);
        $calendars = $query->paginate($perPage);

        return response()->json($calendars);
    }

    /**
     * Store a newly created calendar entry.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'date' => 'required|date',
                'type' => 'nullable|string|in:holiday,vacation,sick,other',
                'description' => 'required|string|max:1024',
                'multiplier' => 'nullable|numeric|min:0|max:9.99',
            ]);

            $calendar = Calendar::create([
                'date' => $validatedData['date'],
                'type' => $validatedData['type'] ?? 'other',
                'description' => $validatedData['description'],
                'multiplier' => $validatedData['multiplier'] ?? 1,
            ]);

            return response()->json([
                'message' => 'Calendar entry created successfully',
                'data' => $calendar,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    /**
     * Display the specified calendar entry.
     */
    public function show(Calendar $calendar): JsonResponse
    {
        return response()->json($calendar, 200);
    }

    /**
     * Update the specified calendar entry.
     */
    public function update(Request $request, Calendar $calendar): JsonResponse
    {
        try {
            if (empty($request->all())) {
                return response()->json([
                    'message' => 'No data provided for update',
                    'data' => $calendar,
                ], 200);
            }

            $validatedData = $request->validate([
                'date' => 'sometimes|date',
                'type' => 'sometimes|string|in:holiday,vacation,sick,other',
                'description' => 'sometimes|string|max:1024',
                'multiplier' => 'sometimes|numeric|min:0|max:9.99',
            ]);

            $calendar->update($validatedData);

            return response()->json([
                'message' => 'Calendar entry updated successfully',
                'data' => $calendar,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    /**
     * Remove the specified calendar entry.
     */
    public function destroy(Calendar $calendar): JsonResponse
    {
        $calendar->delete();
        return response()->json(['message' => 'Calendar entry deleted successfully.']);
    }
}
