<?php

namespace App\Http\Controllers;

use App\Models\EmploymentDetail;
use App\Models\Employee;
use App\Models\Department;
use App\Models\EmployeeStatus;
use App\Models\PayrateFrequency;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class EmploymentDetailsController extends Controller
{
    public function index(Request $request)
    {
        $query = EmploymentDetail::with([
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

        // Filter by active status
        if ($request->has('isActive')) {
            $query->where('isActive', $request->boolean('isActive'));
        }

        // Sort
        if ($request->has('sortBy')) {
            $sortDirection = $request->input('sortDirection', 'asc');
            $query->orderBy($request->input('sortBy'), $sortDirection);
        } else {
            $query->orderBy('created_at', 'desc');
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

        // Check if employee already has employment details
        $exists = EmploymentDetail::where('employeeId', $request->employeeId)
            ->where('isActive', true)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Employee already has active employment details'
            ], 422);
        }

        $employmentDetails = EmploymentDetail::create($request->all());

        return response()->json($employmentDetails->load([
            'employee',
            'department',
            'employeeStatus',
            'payrateFrequency',
            'paymentMethod'
        ]), 201);
    }

    public function show(EmploymentDetail $employmentDetails)
    {
        return $employmentDetails->load([
            'employee',
            'department',
            'employeeStatus',
            'payrateFrequency',
            'paymentMethod'
        ]);
    }

    public function update(Request $request, EmploymentDetail $employmentDetails)
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

        // If employee is being changed, check for active employment details
        if ($request->has('employeeId') && $request->employeeId !== $employmentDetails->employeeId) {
            $exists = EmploymentDetail::where('employeeId', $request->employeeId)
                ->where('isActive', true)
                ->exists();

            if ($exists) {
                return response()->json([
                    'message' => 'Employee already has active employment details'
                ], 422);
            }
        }

        $employmentDetails->update($request->all());

        return response()->json($employmentDetails->load([
            'employee',
            'department',
            'employeeStatus',
            'payrateFrequency',
            'paymentMethod'
        ]));
    }

    public function destroy(EmploymentDetail $employmentDetails)
    {
        $employmentDetails->delete();
        return response()->json(null, 204);
    }
}