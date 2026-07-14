<?php

namespace App\Modules\Hr\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\EmploymentHistory;
use Illuminate\Support\Facades\Validator;

class EmploymentHistoryController extends Controller
{
    public function index(Request $request)
    {
        $query = EmploymentHistory::query()->orderBy('from', 'desc');
        return $query->paginate($request->input('per_page', 15));
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employeeId' => ['required', 'uuid', 'exists:employees,id'],
            'employerName' => ['required', 'string', 'max:255'],
            'positionHeld' => ['nullable', 'string', 'max:255'],
            'from' => ['required', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'note' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $record = EmploymentHistory::create($validator->validated());
        return response()->json($record, 201);
    }

    public function show(EmploymentHistory $employmentHistory)
    {
        return response()->json($employmentHistory);
    }

    public function update(Request $request, EmploymentHistory $employmentHistory)
    {
        $validator = Validator::make($request->all(), [
            'employeeId' => ['sometimes', 'uuid', 'exists:employees,id'],
            'employerName' => ['sometimes', 'string', 'max:255'],
            'positionHeld' => ['nullable', 'string', 'max:255'],
            'from' => ['sometimes', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'note' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $employmentHistory->update($validator->validated());
        return response()->json($employmentHistory);
    }

    public function destroy(EmploymentHistory $employmentHistory)
    {
        $employmentHistory->delete();
        return response()->json(null, 204);
    }
}


