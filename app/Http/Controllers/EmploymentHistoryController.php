<?php

namespace App\Http\Controllers;

use App\Models\EmploymentHistory;
use App\Models\Employee;
use App\Models\Department;
use App\Models\EmployeeStatus;
use App\Models\PayrateFrequency;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class EmploymentHistoryController extends Controller
{
    public function index(Request $request)
    {
        $query = EmploymentHistory::with([
            'employee',
            'department',
            'employeeStatus',
            'payrateFrequency',
            'paymentMethod'
        ]);

        // Filter by employee
        if ($request->has('employeeId')) {
            $query->where('employeeId', $request->input('employeeId'));
        }

        // Filter by department
        if ($request->has('departmentId')) {
            $query->where('departmentId', $request->input('departmentId'));
        }

        // Filter by employee status
        if ($request->has('employeeStatusId')) {
            $query->where('employeeStatusId', $request->input('employeeStatusId'));
        }

        // Filter by date range
        if ($request->has('startDate')) {
            $query->where('startDate', '>=', $request->input('startDate'));
        }
        if ($request->has('endDate')) {
            $query->where('endDate', '<=', $request->input('endDate'));
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
            $query->orderBy('startDate', 'desc');
        }

        return $query->paginate($request->input('per_page', 15));
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employeeId' => ['required', 'uuid', 'exists:employees,id'],
            'departmentId' => ['required', 'uuid', 'exists:departments,id'],
            'employeeStatusId' => ['required', 'uuid', 'exists:employee_statuses,id'],
            'payrateFrequencyId' => ['required', 'uuid', 'exists:payrate_frequencies,id'],
            'paymentMethodId' => ['required', 'uuid', 'exists:payment_methods,id'],
            'payrate' => ['required', 'numeric', 'min:0'],
            'startDate' => ['required', 'date'],
            'endDate' => ['nullable', 'date', 'after:startDate'],
            'isActive' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Check for overlapping employment history
        $overlapping = EmploymentHistory::where('employeeId', $request->employeeId)
            ->where(function($query) use ($request) {
                $query->where(function($q) use ($request) {
                    $q->where('startDate', '<=', $request->startDate)
                      ->where('endDate', '>=', $request->startDate);
                })->orWhere(function($q) use ($request) {
                    $q->where('startDate', '<=', $request->endDate)
                      ->where('endDate', '>=', $request->endDate);
                })->orWhere(function($q) use ($request) {
                    $q->where('startDate', '>=', $request->startDate)
                      ->where('endDate', '<=', $request->endDate);
                });
            })
            ->exists();

        if ($overlapping) {
            return response()->json([
                'message' => 'Employment history period overlaps with existing records'
            ], 422);
        }

        $employmentHistory = EmploymentHistory::create($request->all());

        return response()->json($employmentHistory->load([
            'employee',
            'department',
            'employeeStatus',
            'payrateFrequency',
            'paymentMethod'
        ]), 201);
    }

    public function show(EmploymentHistory $employmentHistory)
    {
        return $employmentHistory->load([
            'employee',
            'department',
            'employeeStatus',
            'payrateFrequency',
            'paymentMethod'
        ]);
    }

    public function update(Request $request, EmploymentHistory $employmentHistory)
    {
        if ($request->isMethod('put') && empty($request->all())) {
            return response()->json([
                'message' => 'No data provided for update'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'employeeId' => ['sometimes', 'uuid', 'exists:employees,id'],
            'departmentId' => ['sometimes', 'uuid', 'exists:departments,id'],
            'employeeStatusId' => ['sometimes', 'uuid', 'exists:employee_statuses,id'],
            'payrateFrequencyId' => ['sometimes', 'uuid', 'exists:payrate_frequencies,id'],
            'paymentMethodId' => ['sometimes', 'uuid', 'exists:payment_methods,id'],
            'payrate' => ['sometimes', 'numeric', 'min:0'],
            'startDate' => ['sometimes', 'date'],
            'endDate' => ['nullable', 'date', 'after:startDate'],
            'isActive' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // If dates are being changed, check for overlapping employment history
        if ($request->has('startDate') || $request->has('endDate')) {
            $startDate = $request->input('startDate', $employmentHistory->startDate);
            $endDate = $request->input('endDate', $employmentHistory->endDate);

            $overlapping = EmploymentHistory::where('id', '!=', $employmentHistory->id)
                ->where('employeeId', $request->input('employeeId', $employmentHistory->employeeId))
                ->where(function($query) use ($startDate, $endDate) {
                    $query->where(function($q) use ($startDate, $endDate) {
                        $q->where('startDate', '<=', $startDate)
                          ->where('endDate', '>=', $startDate);
                    })->orWhere(function($q) use ($startDate, $endDate) {
                        $q->where('startDate', '<=', $endDate)
                          ->where('endDate', '>=', $endDate);
                    })->orWhere(function($q) use ($startDate, $endDate) {
                        $q->where('startDate', '>=', $startDate)
                          ->where('endDate', '<=', $endDate);
                    });
                })
                ->exists();

            if ($overlapping) {
                return response()->json([
                    'message' => 'Employment history period overlaps with existing records'
                ], 422);
            }
        }

        $employmentHistory->update($request->all());

        return response()->json($employmentHistory->load([
            'employee',
            'department',
            'employeeStatus',
            'payrateFrequency',
            'paymentMethod'
        ]));
    }

    public function destroy(EmploymentHistory $employmentHistory)
    {
        $employmentHistory->delete();
        return response()->json(null, 204);
    }
} 