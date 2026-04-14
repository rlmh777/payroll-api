<?php

namespace App\Http\Controllers;

use App\Models\WorkTimesheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WorkTimesheetController extends Controller
{
    /**
     * Display a listing of work timesheets.
     */
    public function index(): JsonResponse
    {
        $timesheets = WorkTimesheet::query()->orderBy('name')->get();

        return response()->json($timesheets);
    }

    /**
     * Store a newly created work timesheet.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'days' => ['nullable', 'array'],
            'days.*' => ['string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $timesheet = WorkTimesheet::create([
            'id' => (string) Str::uuid(),
            'name' => $validated['name'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'break_minutes' => $validated['break_minutes'] ?? 0,
            'days' => $validated['days'] ?? [],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json($timesheet, 201);
    }
}
