<?php

namespace App\Http\Controllers;

use App\Models\BankAccountType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BankAccountTypeController extends Controller
{
    public function index(Request $request)
    {
        $query = BankAccountType::query();

        // Search by name
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%");
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
            'name' => ['required', 'string', 'max:128'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $bankAccountType = BankAccountType::create($request->all());

        return response()->json($bankAccountType, 201);
    }

    public function show(BankAccountType $bankAccountType)
    {
        return $bankAccountType;
    }

    public function update(Request $request, BankAccountType $bankAccountType)
    {
        if ($request->isMethod('put') && empty($request->all())) {
            return response()->json([
                'message' => 'No data provided for update'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:128'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $bankAccountType->update($request->all());

        return response()->json($bankAccountType);
    }

    public function destroy(BankAccountType $bankAccountType)
    {
        $bankAccountType->delete();
        return response()->json(null, 204);
    }
} 