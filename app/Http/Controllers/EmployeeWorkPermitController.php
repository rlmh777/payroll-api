<?php

namespace App\Http\Controllers;

use App\Models\EmployeeWorkPermit;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class EmployeeWorkPermitController extends Controller
{
    public function index(Request $request)
    {
        $query = EmployeeWorkPermit::with(['employee']);

        // Filter by employee
        if ($request->has('employeeId')) {
            $query->where('employeeId', $request->input('employeeId'));
        }

        // Filter by work permit number
        if ($request->has('workPermitNumber')) {
            $query->where('workPermitNumber', 'ilike', '%' . $request->input('workPermitNumber') . '%');
        }

        // Filter by social security number
        if ($request->has('socialSecurityNumber')) {
            $query->where('socialSecurityNumber', 'ilike', '%' . $request->input('socialSecurityNumber') . '%');
        }

        // Filter by expiration status
        if ($request->has('expired')) {
            $query->where('expires', '<', Carbon::now());
        } elseif ($request->has('active')) {
            $query->where('expires', '>=', Carbon::now());
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
            'employeeId' => ['required', 'uuid', 'exists:employee,id'],
            'workPermitNumber' => ['required', 'string', 'max:255'],
            'issued' => ['required', 'date'],
            'expires' => ['required', 'date', 'after:issued'],
            'socialSecurityNumber' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $employeeWorkPermit = EmployeeWorkPermit::create($request->all());

        return response()->json($employeeWorkPermit->load(['employee']), 201);
    }

    public function show(EmployeeWorkPermit $employeeWorkPermit)
    {
        return $employeeWorkPermit->load(['employee']);
    }

    public function update(Request $request, EmployeeWorkPermit $employeeWorkPermit)
    {
        if ($request->isMethod('put') && empty($request->all())) {
            return response()->json([
                'message' => 'No data provided for update'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'employeeId' => ['sometimes', 'uuid', 'exists:employee,id'],
            'workPermitNumber' => ['sometimes', 'string', 'max:255'],
            'issued' => ['sometimes', 'date'],
            'expires' => ['sometimes', 'date', 'after:issued'],
            'socialSecurityNumber' => ['sometimes', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $employeeWorkPermit->update($request->all());

        return response()->json($employeeWorkPermit->load(['employee']));
    }

    public function destroy(EmployeeWorkPermit $employeeWorkPermit)
    {
        $employeeWorkPermit->delete();
        return response()->json(null, 204);
    }
} 