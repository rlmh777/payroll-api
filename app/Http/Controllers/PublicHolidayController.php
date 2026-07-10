<?php

namespace App\Http\Controllers;

use App\Models\PublicHoliday;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicHolidayController extends Controller
{
    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'startDate' => "{$required}|date",
            'endDate' => "{$required}|date|after_or_equal:startDate",
            'name' => "{$required}|string|max:1024",
            'payMultiplier' => 'nullable|numeric|min:0|max:9.99',
            'isActive' => 'nullable|boolean',
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $query = PublicHoliday::query();

        if ($request->filled('start') && $request->filled('end')) {
            $query->whereDate('startDate', '<=', $request->string('end'))
                ->whereDate('endDate', '>=', $request->string('start'));
        }

        if ($request->has('is_active')) {
            $query->where('isActive', $request->boolean('is_active'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('name', 'ilike', "%{$search}%");
        }

        $sortField = $request->get('sort_by', 'startDate');
        $sortDirection = $request->get('sort_direction', 'asc');

        if (in_array($sortField, ['startDate', 'endDate', 'name', 'payMultiplier'], true)) {
            $query->orderBy($sortField, $sortDirection);
        } else {
            $query->orderBy('startDate', 'asc');
        }

        return response()->json($query->paginate((int) $request->get('per_page', 50)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

        $holiday = PublicHoliday::create([
            'startDate' => $validated['startDate'],
            'endDate' => $validated['endDate'],
            'name' => $validated['name'],
            'payMultiplier' => $validated['payMultiplier'] ?? 1.5,
            'isActive' => $validated['isActive'] ?? true,
        ]);

        return response()->json([
            'message' => 'Public holiday created successfully',
            'data' => $holiday,
        ], 201);
    }

    public function show(PublicHoliday $publicHoliday): JsonResponse
    {
        return response()->json($publicHoliday);
    }

    public function update(Request $request, PublicHoliday $publicHoliday): JsonResponse
    {
        if ($request->isMethod('put') && empty($request->all())) {
            return response()->json(['message' => 'No data provided for update'], 422);
        }

        $validated = $request->validate($this->rules(true));
        $publicHoliday->update($validated);

        return response()->json([
            'message' => 'Public holiday updated successfully',
            'data' => $publicHoliday->fresh(),
        ]);
    }

    public function destroy(PublicHoliday $publicHoliday): JsonResponse
    {
        $publicHoliday->delete();

        return response()->json(['message' => 'Public holiday deleted successfully.']);
    }
}
