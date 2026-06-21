<?php

namespace App\Http\Controllers;

use App\Models\PayrateFrequency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PayrateFrequencyController extends Controller
{
    public function index(Request $request)
    {
        $query = PayrateFrequency::query();

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
            'name' => ['required', 'string', 'max:64', 'unique:payrate_frequency,name'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $payrateFrequency = PayrateFrequency::create($validator->validated());

        return response()->json($payrateFrequency, 201);
    }

    public function show(PayrateFrequency $payrateFrequency)
    {
        return $payrateFrequency;
    }

    public function update(Request $request, PayrateFrequency $payrateFrequency)
    {
        if ($request->isMethod('put') && empty($request->all())) {
            return response()->json([
                'message' => 'No data provided for update'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:64', 'unique:payrate_frequency,name,' . $payrateFrequency->id],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $payrateFrequency->update($validator->validated());

        return response()->json($payrateFrequency);
    }

    public function destroy(PayrateFrequency $payrateFrequency)
    {
        $payrateFrequency->delete();

        return response()->json(null, 204);
    }
}

