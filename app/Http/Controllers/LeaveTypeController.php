<?php

namespace App\Http\Controllers;

use App\Models\LeaveType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
class LeaveTypeController extends Controller
{
    public function index(Request $request)
    {
        $query = LeaveType::query();

        // Search by name
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('name', 'ilike', "%{$search}%");
        }

        // Sort
        if ($request->has('sortBy')) {
            $sortDirection = $request->input('sortDirection', 'asc');
            $query->orderBy($request->input('sortBy'), $sortDirection);
        } else {
            $query->orderBy('name', 'asc');
        }

        return $query->paginate($request->input('per_page', 15));
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255', 'unique:leave_type,name'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $leaveType = LeaveType::create($request->all());

        return response()->json($leaveType, 201);
    }

    public function show(LeaveType $leaveType)
    {
        return $leaveType;
    }

    public function update(Request $request, LeaveType $leaveType)
    {
        if ($request->isMethod('put') && empty($request->all())) {
            return response()->json([
                'message' => 'No data provided for update'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:255', 'unique:leave_type,name,' . $leaveType->id],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $leaveType->update($request->all());

        return response()->json($leaveType);
    }

    public function destroy(LeaveType $leaveType)
    {
        $leaveType->delete();
        return response()->json(null, 204);
    }
} 