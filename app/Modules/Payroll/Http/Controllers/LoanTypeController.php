<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\LoanType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LoanTypeController extends Controller
{
    public function index(Request $request)
    {
        $query = LoanType::query();

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
            'name' => ['required', 'string', 'max:128', 'unique:loan_type,name'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $loanType = LoanType::create($request->all());

        return response()->json($loanType, 201);
    }

    public function show(LoanType $loanType)
    {
        return $loanType;
    }

    public function update(Request $request, LoanType $loanType)
    {
        if ($request->isMethod('put') && empty($request->all())) {
            return response()->json([
                'message' => 'No data provided for update'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:128', 'unique:loan_type,name,' . $loanType->id],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $loanType->update($request->all());

        return response()->json($loanType);
    }

    public function destroy(LoanType $loanType)
    {
        $loanType->delete();
        return response()->json(null, 204);
    }
} 