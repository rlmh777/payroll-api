<?php

namespace App\Http\Controllers;

use App\Models\TimesheetTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TimesheetTemplateController extends Controller
{
    /**
     * Display a listing of timesheet templates.
     */
    public function index(): JsonResponse
    {
        $templates = TimesheetTemplate::query()->orderBy('name')->get();

        return response()->json($templates);
    }

    /**
     * Store a newly created timesheet template.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        $template = new TimesheetTemplate([
            'id' => (string) Str::uuid(),
            'name' => $validated['name'],
            'is_active' => $validated['is_active'] ?? true,
            'break_minutes' => 60,
        ]);

        $template->applyDaySchedules($validated['day_schedules']);
        $template->save();

        return response()->json($template->fresh(), 201);
    }

    /**
     * Update an existing timesheet template.
     */
    public function update(Request $request, TimesheetTemplate $timesheetTemplate): JsonResponse
    {
        $validated = $this->validatePayload($request);

        $timesheetTemplate->name = $validated['name'];
        $timesheetTemplate->is_active = $validated['is_active'] ?? $timesheetTemplate->is_active;
        $timesheetTemplate->applyDaySchedules($validated['day_schedules']);
        $timesheetTemplate->save();

        return response()->json($timesheetTemplate->fresh());
    }

    /**
     * @return array{name:string,day_schedules:array<int,array<string,mixed>>,is_active?:bool}
     */
    private function validatePayload(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'day_schedules' => ['required', 'array', 'min:1'],
            'day_schedules.*.day' => ['required', 'string', 'in:Mon,Tue,Wed,Thu,Fri,Sat,Sun'],
            'day_schedules.*.start_time' => ['required', 'date_format:H:i'],
            'day_schedules.*.end_time' => ['required', 'date_format:H:i'],
            'day_schedules.*.include_lunch_hour' => ['required', 'boolean'],
            'day_schedules.*.lunch_hour_hours' => ['nullable', 'numeric', 'min:0', 'max:8'],
            'day_schedules.*.department_id' => ['required', 'integer', 'exists:department,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $normalized = TimesheetTemplate::normalizeDaySchedules($validated['day_schedules']);

        if ($normalized === []) {
            throw ValidationException::withMessages([
                'day_schedules' => ['At least one valid day schedule is required.'],
            ]);
        }

        $validated['day_schedules'] = $normalized;

        return $validated;
    }
}
