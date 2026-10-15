<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\EmployeeHoursWorked;
use App\Models\Employee;
use App\Models\PayrateFrequency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

// TODO: Add time breakdowns to the employee hours worked model

class EmployeeHoursWorkedController extends Controller
{
    public function index(Request $request)
    {
        $query = EmployeeHoursWorked::with(['employee', 'payrateFrequency']);

        // Filter by employee
        if ($request->has('employeeId')) {
            $query->where('employeeId', $request->input('employeeId'));
        }

        // Filter by payrate frequency
        if ($request->has('payrateFrequencyId')) {
            $query->where('payrateFrequencyId', $request->input('payrateFrequencyId'));
        }

        // Filter by date range
        if ($request->has('startDate')) {
            $query->where('date', '>=', $request->input('startDate'));
        }
        if ($request->has('endDate')) {
            $query->where('date', '<=', $request->input('endDate'));
        }

        // Filter by active status
        if ($request->has('isActive')) {
            $query->where('isActive', $request->boolean('isActive'));
        }

        // Sort
        if ($request->has('sortBy')) {
            $sortDirection = $request->input('sortDirection', 'asc');
            $query->orderBy($request->input('sortBy'), $sortDirection);
        } else {
            $query->orderBy('date', 'desc');
        }

        return $query->paginate($request->input('per_page', 15));
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employeeId' => ['required', 'uuid', 'exists:employees,id'],
            'payrateFrequencyId' => ['required', 'uuid', 'exists:payrate_frequencies,id'],
            'date' => ['required', 'date'],
            'hoursWorked' => ['required', 'numeric', 'min:0'],
            'isActive' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Check if hours worked already exists for this employee and date
        $exists = EmployeeHoursWorked::where('employeeId', $request->employeeId)
            ->where('date', $request->date)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Hours worked already exists for this employee and date'
            ], 422);
        }

        $hoursWorked = EmployeeHoursWorked::create($request->all());

        return response()->json($hoursWorked->load(['employee', 'payrateFrequency']), 201);
    }

    public function show(EmployeeHoursWorked $hoursWorked)
    {
        return $hoursWorked->load(['employee', 'payrateFrequency']);
    }

    public function update(Request $request, EmployeeHoursWorked $hoursWorked)
    {
        if ($request->isMethod('put') && empty($request->all())) {
            return response()->json([
                'message' => 'No data provided for update'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'employeeId' => ['sometimes', 'uuid', 'exists:employees,id'],
            'payrateFrequencyId' => ['sometimes', 'uuid', 'exists:payrate_frequencies,id'],
            'date' => ['sometimes', 'date'],
            'hoursWorked' => ['sometimes', 'numeric', 'min:0'],
            'isActive' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // If employee or date is being changed, check for duplicates
        if (($request->has('employeeId') && $request->employeeId !== $hoursWorked->employeeId) ||
            ($request->has('date') && $request->date !== $hoursWorked->date)) {
            $exists = EmployeeHoursWorked::where('id', '!=', $hoursWorked->id)
                ->where('employeeId', $request->input('employeeId', $hoursWorked->employeeId))
                ->where('date', $request->input('date', $hoursWorked->date))
                ->exists();

            if ($exists) {
                return response()->json([
                    'message' => 'Hours worked already exists for this employee and date'
                ], 422);
            }
        }

        $hoursWorked->update($request->all());

        return response()->json($hoursWorked->load(['employee', 'payrateFrequency']));
    }

    public function destroy(EmployeeHoursWorked $hoursWorked)
    {
        $hoursWorked->delete();
        return response()->json(null, 204);
    }
} 