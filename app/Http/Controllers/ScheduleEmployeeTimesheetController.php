<?php

namespace App\Http\Controllers;

use App\Models\CalendarGroup;
use App\Models\ScheduleEmployeeTimesheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduleEmployeeTimesheetController extends Controller
{
    /**
     * Store a newly created schedule timesheet.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employeeId' => ['required', 'string', 'exists:employee,id'],
            'date' => ['required', 'date'],
            'startTime' => ['required', 'date_format:H:i'],
            'endTime' => ['required', 'date_format:H:i'],
        ]);

        $scheduleGroup = CalendarGroup::where('key', 'schedules')->first();

        $schedule = ScheduleEmployeeTimesheet::create([
            'employeeId' => $validated['employeeId'],
            'date' => $validated['date'],
            'startTime' => $validated['startTime'],
            'endTime' => $validated['endTime'],
            'approvalStatus' => 'pending',
            'calendar_group_id' => $scheduleGroup?->id,
        ]);

        return response()->json($schedule, 201);
    }
}
