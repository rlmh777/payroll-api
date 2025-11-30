<?php

namespace App\Http\Controllers;

use App\Models\EmployeeLeave;
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
            'notes' => ['nullable', 'string'],
            'multiplier' => ['nullable', 'numeric', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $employeeLeave = EmployeeLeave::create($request->all());

        return response()->json($employeeLeave->load(['employee', 'leaveType']), 201);
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
            'notes' => ['nullable', 'string'],
            'multiplier' => ['nullable', 'numeric', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // If updating dates, ensure endDate is after or equal to startDate
        $startDate = $request->input('startDate', $employeeLeave->startDate);
        $endDate = $request->input('endDate', $employeeLeave->endDate);
        
        if ($startDate && $endDate && Carbon::parse($endDate)->lt(Carbon::parse($startDate))) {
            return response()->json([
                'errors' => ['endDate' => ['The end date must be after or equal to the start date.']]
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
}

