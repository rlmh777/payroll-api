<?php

namespace App\Http\Controllers;

use App\Models\PayPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PayPeriodController extends Controller
{
    public function index()
    {
        return response()->json(PayPeriod::query()->latest('start_date')->paginate());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'id' => ['required','uuid'],
            'start_date' => ['required','date'],
            'end_date' => ['required','date','after_or_equal:start_date'],
            'pay_date' => ['required','date','after_or_equal:end_date'],
        ]);

        $payPeriod = PayPeriod::create($data);

        return response()->json($payPeriod, Response::HTTP_CREATED);
    }

    public function show(PayPeriod $payPeriod)
    {
        return response()->json($payPeriod);
    }

    public function update(Request $request, PayPeriod $payPeriod)
    {
        $data = $request->validate([
            'start_date' => ['sometimes','required','date'],
            'end_date' => ['sometimes','required','date','after_or_equal:start_date'],
            'pay_date' => ['sometimes','required','date','after_or_equal:end_date'],
        ]);

        $payPeriod->update($data);

        return response()->json($payPeriod);
    }

    public function destroy(PayPeriod $payPeriod)
    {
        $payPeriod->delete();
        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}


