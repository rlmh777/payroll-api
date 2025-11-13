<?php

namespace App\Http\Controllers;

use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PaymentMethodController extends Controller
{
    public function index(Request $request)
    {
        $query = PaymentMethod::query();

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
            'name' => ['required', 'string', 'max:64', 'unique:payment_method,name'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $paymentMethod = PaymentMethod::create($request->all());

        return response()->json($paymentMethod, 201);
    }

    public function show(PaymentMethod $paymentMethod)
    {
        return $paymentMethod;
    }

    public function update(Request $request, PaymentMethod $paymentMethod)
    {
        if ($request->isMethod('put') && empty($request->all())) {
            return response()->json([
                'message' => 'No data provided for update'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:64', 'unique:payment_method,name,' . $paymentMethod->id],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $paymentMethod->update($request->all());

        return response()->json($paymentMethod);
    }

    public function destroy(PaymentMethod $paymentMethod)
    {
        $paymentMethod->delete();
        return response()->json(null, 204);
    }
} 