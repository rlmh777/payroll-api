<?php

namespace App\Http\Controllers;

use App\Models\PayPeriodSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PayPeriodController extends Controller
{
    public function index()
    {
        return response()->json(PayPeriodSchedule::query()->latest('start_date')->paginate());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'id' => ['required','uuid'],
            'start_date' => ['required','date'],
            'end_date' => ['required','date','after_or_equal:start_date'],
            'pay_date' => ['required','date','after_or_equal:end_date'],
        ]);

        $payPeriodSchedule = PayPeriodSchedule::create($data);

        return response()->json($payPeriodSchedule, Response::HTTP_CREATED);
    }

    public function show(PayPeriodSchedule $payPeriodSchedule)
    {
        return response()->json($payPeriodSchedule);
    }

    public function update(Request $request, PayPeriodSchedule $payPeriodSchedule)
    {
        $data = $request->validate([
            'start_date' => ['sometimes','required','date'],
            'end_date' => ['sometimes','required','date','after_or_equal:start_date'],
            'pay_date' => ['sometimes','required','date','after_or_equal:end_date'],
        ]);

        $payPeriodSchedule->update($data);

        return response()->json($payPeriodSchedule);
    }

    public function destroy(PayPeriodSchedule $payPeriodSchedule)
    {
        $payPeriodSchedule->delete();
        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}


