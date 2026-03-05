<?php

namespace App\Http\Controllers;

use App\Models\Calendar;
use App\Models\CalendarGroup;
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

        if ($request->filled('calendar_group_id')) {
            $query->where('calendar_group_id', $request->string('calendar_group_id'));
        }

        $sortField = $request->get('sort_by', 'date');
        $sortDirection = $request->get('sort_direction', 'asc');

        if (in_array($sortField, ['date', 'description', 'rate'])) {
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
                'calendar_group_id' => 'nullable|uuid|exists:calendar_groups,id',
                'type' => 'nullable|string|in:holiday,vacation,sick,other,timesheet,schedule',
                'description' => 'required|string|max:1024',
                'rate' => 'nullable|numeric|min:0|max:9.99',
            ]);

            $defaultGroup = CalendarGroup::where('key', 'general')->first();

            $calendar = Calendar::create([
                'date' => $validatedData['date'],
                'calendar_group_id' => $validatedData['calendar_group_id'] ?? $defaultGroup?->id,
                'type' => $validatedData['type'] ?? 'other',
                'description' => $validatedData['description'],
                'rate' => $validatedData['rate'] ?? 1,
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
                'calendar_group_id' => 'sometimes|uuid|exists:calendar_groups,id',
                'type' => 'sometimes|string|in:holiday,vacation,sick,other,timesheet,schedule',
                'description' => 'sometimes|string|max:1024',
                'rate' => 'sometimes|numeric|min:0|max:9.99',
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
