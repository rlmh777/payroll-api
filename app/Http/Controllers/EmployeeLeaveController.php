<?php

namespace App\Http\Controllers;

use App\Models\EmployeeLeave;
use App\Helpers\EmployeeLeaveHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class EmployeeLeaveController extends Controller
{
    public function index(Request $request)
    {
        $query = EmployeeLeave::with(['employee', 'leaveType']);

        // Filter by employee
        if ($request->has('employeeId')) {
            $query->where('employeeId', $request->input('employeeId'));
        }

        // Filter by leave type
        if ($request->has('leaveTypeId')) {
            $query->where('leaveTypeId', $request->input('leaveTypeId'));
        }

        // Filter by start date (leaves that start on or after this date)
        if ($request->has('startDate')) {
            $query->where('startDate', '>=', $request->input('startDate'));
        }

        // Filter by end date (leaves that end on or before this date)
        if ($request->has('endDate')) {
            $query->where('endDate', '<=', $request->input('endDate'));
        }

        // Sort
        if ($request->has('sortBy')) {
            $sortDirection = $request->input('sortDirection', 'asc');
            $query->orderBy($request->input('sortBy'), $sortDirection);
        } else {
            $query->orderBy('startDate', 'desc');
        }

        return $query->paginate($request->input('per_page', 15));
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employeeId' => ['required', 'uuid', 'exists:employee,id'],
            'leaveTypeId' => ['required', 'integer', 'exists:leave_type,id'],
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date', 'after_or_equal:startDate'],
            'fromTime' => ['required', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/'],
            'toTime' => ['required', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/'],
            'duration' => ['required', 'in:Full Day,All Days,Morning,Afternoon,Custom'],
            'totalDays' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'multiplier' => ['nullable', 'numeric', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $startDate = Carbon::parse($request->input('startDate'));
        $endDate = Carbon::parse($request->input('endDate'));
        $duration = $request->input('duration');
        $employeeId = $request->input('employeeId');
        
        // Check for overlapping leaves
        $overlappingLeaves = self::checkForOverlappingLeaves(
            $employeeId,
            $startDate->format('Y-m-d'),
            $endDate->format('Y-m-d'),
            $duration,
            $request->input('fromTime'),
            $request->input('toTime')
        );
        
        if (!empty($overlappingLeaves)) {
            return response()->json([
                'errors' => ['overlap' => ['The leave period overlaps with existing leave(s).']]
            ], 422);
        }
        
        // Count working days in the date range (excluding weekends)
        $workingDaysCount = EmployeeLeaveHelper::countWorkingDays($startDate, $endDate);
        
        // If it's a single working day, create one entry
        if ($workingDaysCount <= 1) {
            $employeeLeave = EmployeeLeave::create($request->all());
            return response()->json($employeeLeave->load(['employee', 'leaveType']), 201);
        }

        // Check if dates are consecutive and duration is Full Day or All Days
        $isFullDay = in_array($duration, ['Full Day', 'All Days']);
        $areConsecutive = EmployeeLeaveHelper::areWorkingDaysConsecutive($startDate, $endDate);
        
        // If consecutive full days, create one entry
        if ($isFullDay && $areConsecutive) {
            // Check for overlaps for the entire range
            $overlappingLeaves = self::checkForOverlappingLeaves(
                $employeeId,
                $startDate->format('Y-m-d'),
                $endDate->format('Y-m-d'),
                $duration,
                $request->input('fromTime'),
                $request->input('toTime')
            );
            
            if (!empty($overlappingLeaves)) {
                return response()->json([
                    'errors' => ['overlap' => ['The leave period overlaps with existing leave(s).']]
                ], 422);
            }
            
            // Calculate total days for the entire range
            $totalDays = $workingDaysCount * 1.0; // Full day = 1.0 per day
            
            $leaveData = [
                'employeeId' => $request->input('employeeId'),
                'leaveTypeId' => $request->input('leaveTypeId'),
                'startDate' => $startDate->format('Y-m-d'),
                'endDate' => $endDate->format('Y-m-d'),
                'fromTime' => $request->input('fromTime'),
                'toTime' => $request->input('toTime'),
                'duration' => $duration,
                'totalDays' => $totalDays,
                'notes' => $request->input('notes'),
                'multiplier' => $request->input('multiplier', 1),
            ];
            
            $employeeLeave = EmployeeLeave::create($leaveData);
            return response()->json($employeeLeave->load(['employee', 'leaveType']), 201);
        }

        // For multiple working days that are not consecutive full days, create one entry per working day
        $workingDays = EmployeeLeaveHelper::getWorkingDays($startDate, $endDate);
        $createdLeaves = [];
        
        foreach ($workingDays as $currentDate) {
            // Check for overlaps for this specific day
            $dayStart = $currentDate->format('Y-m-d');
            $dayEnd = $currentDate->format('Y-m-d');
            
            $overlappingLeaves = self::checkForOverlappingLeaves(
                $employeeId,
                $dayStart,
                $dayEnd,
                $duration,
                $request->input('fromTime'),
                $request->input('toTime')
            );
            
            if (!empty($overlappingLeaves)) {
                return response()->json([
                    'errors' => ['overlap' => ['The leave period overlaps with existing leave(s).']]
                ], 422);
            }
            
            // Calculate totalDays for this specific day based on duration
            $totalDays = EmployeeLeaveHelper::calculateTotalDaysForDuration(
                $duration,
                $request->input('fromTime'),
                $request->input('toTime')
            );
            
            $leaveData = [
                'employeeId' => $request->input('employeeId'),
                'leaveTypeId' => $request->input('leaveTypeId'),
                'startDate' => $dayStart,
                'endDate' => $dayEnd,
                'fromTime' => $request->input('fromTime'),
                'toTime' => $request->input('toTime'),
                'duration' => $duration,
                'totalDays' => $totalDays,
                'notes' => $request->input('notes'),
                'multiplier' => $request->input('multiplier', 1),
            ];
            
            $employeeLeave = EmployeeLeave::create($leaveData);
            $createdLeaves[] = $employeeLeave->load(['employee', 'leaveType']);
        }

        // If no working days were found, return an error
        if (empty($createdLeaves)) {
            return response()->json([
                'errors' => ['dateRange' => ['No working days found in the selected date range.']]
            ], 422);
        }

        return response()->json($createdLeaves, 201);
    }

    public function show(EmployeeLeave $employeeLeave)
    {
        return $employeeLeave->load(['employee', 'leaveType']);
    }

    public function update(Request $request, EmployeeLeave $employeeLeave)
    {
        if ($request->isMethod('put') && empty($request->all())) {
            return response()->json([
                'message' => 'No data provided for update'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'employeeId' => ['sometimes', 'uuid', 'exists:employee,id'],
            'leaveTypeId' => ['sometimes', 'integer', 'exists:leave_type,id'],
            'startDate' => ['sometimes', 'date'],
            'endDate' => ['sometimes', 'date'],
            'fromTime' => ['required', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/'],
            'toTime' => ['required', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/'],
            'duration' => ['required', 'in:Full Day,All Days,Morning,Afternoon,Custom'],
            'totalDays' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'multiplier' => ['nullable', 'numeric', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // If updating dates, ensure endDate is after or equal to startDate
        $startDate = $request->input('startDate', $employeeLeave->startDate);
        $endDate = $request->input('endDate', $employeeLeave->endDate);
        $duration = $request->input('duration', $employeeLeave->duration);
        $fromTime = $request->input('fromTime', $employeeLeave->fromTime);
        $toTime = $request->input('toTime', $employeeLeave->toTime);
        $employeeId = $request->input('employeeId', $employeeLeave->employeeId);
        
        if ($startDate && $endDate && Carbon::parse($endDate)->lt(Carbon::parse($startDate))) {
            return response()->json([
                'errors' => ['endDate' => ['The end date must be after or equal to the start date.']]
            ], 422);
        }

        // Check for overlapping leaves (excluding the current leave being updated)
        $overlappingLeaves = self::checkForOverlappingLeaves(
            $employeeId,
            $startDate,
            $endDate,
            $duration,
            $fromTime,
            $toTime,
            $employeeLeave->id // Exclude current leave
        );
        
        if (!empty($overlappingLeaves)) {
            return response()->json([
                'errors' => ['overlap' => ['The leave period overlaps with existing leave(s).']]
            ], 422);
        }

        $employeeLeave->update($request->all());

        return response()->json($employeeLeave->load(['employee', 'leaveType']));
    }

    public function destroy(EmployeeLeave $employeeLeave)
    {
        $employeeLeave->delete();
        return response()->json(null, 204);
    }

    /**
     * Check for overlapping leaves for an employee
     *
     * @param string $employeeId
     * @param string $startDate
     * @param string $endDate
     * @param string $duration
     * @param string|null $fromTime
     * @param string|null $toTime
     * @param string|null $excludeLeaveId Leave ID to exclude from check (for updates)
     * @return array Array of overlapping leaves
     */
    private function checkForOverlappingLeaves(
        string $employeeId,
        string $startDate,
        string $endDate,
        string $duration,
        ?string $fromTime,
        ?string $toTime,
        ?string $excludeLeaveId = null
    ): array {
        // Get all existing leaves for this employee that might overlap
        $query = EmployeeLeave::where('employeeId', $employeeId)
            ->where(function ($q) use ($startDate, $endDate) {
                // Check if date ranges overlap
                $q->where(function ($subQ) use ($startDate, $endDate) {
                    // Existing leave starts before or on new end date
                    // AND existing leave ends after or on new start date
                    $subQ->where('startDate', '<=', $endDate)
                        ->where('endDate', '>=', $startDate);
                });
            });

        // Exclude the current leave if updating
        if ($excludeLeaveId) {
            $query->where('id', '!=', $excludeLeaveId);
        }

        $existingLeaves = $query->get();
        $overlappingLeaves = [];

        foreach ($existingLeaves as $existingLeave) {
            if (EmployeeLeaveHelper::doLeavesOverlap(
                $startDate,
                $endDate,
                $duration,
                $fromTime,
                $toTime,
                $existingLeave->startDate,
                $existingLeave->endDate,
                $existingLeave->duration,
                $existingLeave->fromTime,
                $existingLeave->toTime
            )) {
                $overlappingLeaves[] = $existingLeave;
            }
        }

        return $overlappingLeaves;
    }
}

