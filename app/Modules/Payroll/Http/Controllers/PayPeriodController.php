<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\PayPeriodSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PayPeriodController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = PayPeriodSchedule::with(['payPeriodGroup']);

        if ($request->has('pay_period_group_id')) {
            $query->where('pay_period_group_id', $request->input('pay_period_group_id'));
        }

        return response()->json(
            $query->latest('start_date')->paginate($validated['per_page'] ?? 15)
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'id' => ['required', 'uuid'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'pay_date' => ['required', 'date', 'after_or_equal:end_date'],
            'pay_period_group_id' => ['nullable', 'uuid', 'exists:pay_period_groups,id'],
        ]);

        $payPeriodSchedule = PayPeriodSchedule::create($data);

        return response()->json(
            $payPeriodSchedule->load(['payPeriodGroup']),
            Response::HTTP_CREATED
        );
    }

    public function show(PayPeriodSchedule $payPeriodSchedule)
    {
        return response()->json($payPeriodSchedule->load(['payPeriodGroup']));
    }

    public function update(Request $request, PayPeriodSchedule $payPeriodSchedule)
    {
        $data = $request->validate([
            'start_date' => ['sometimes', 'required', 'date'],
            'end_date' => ['sometimes', 'required', 'date', 'after_or_equal:start_date'],
            'pay_date' => ['sometimes', 'required', 'date', 'after_or_equal:end_date'],
            'pay_period_group_id' => ['nullable', 'uuid', 'exists:pay_period_groups,id'],
        ]);

        $payPeriodSchedule->update($data);

        return response()->json($payPeriodSchedule->load(['payPeriodGroup']));
    }

    public function destroy(PayPeriodSchedule $payPeriodSchedule)
    {
        $payPeriodSchedule->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
