<?php

namespace App\Http\Controllers;

use App\Models\PayPeriodGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PayPeriodGroupController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return response()->json(
            PayPeriodGroup::query()
                ->with('payPeriodSchedules')
                ->latest('created_at')
                ->paginate()
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', 'in:active,inactive'],
            'isDefault' => ['sometimes', 'boolean'],
            'rules' => ['nullable', 'string', 'max:2000'],
        ]);

        $payPeriodGroup = PayPeriodGroup::create($data);

        return response()->json($payPeriodGroup->load('payPeriodSchedules'), Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(PayPeriodGroup $payPeriodGroup)
    {
        return response()->json($payPeriodGroup->load('payPeriodSchedules'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, PayPeriodGroup $payPeriodGroup)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'status' => ['sometimes', 'required', 'in:active,inactive'],
            'isDefault' => ['sometimes', 'boolean'],
            'rules' => ['nullable', 'string', 'max:2000'],
        ]);

        $payPeriodGroup->update($data);

        return response()->json($payPeriodGroup->load('payPeriodSchedules'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(PayPeriodGroup $payPeriodGroup)
    {
        $payPeriodGroup->delete();
        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
