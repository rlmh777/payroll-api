<?php

namespace App\Http\Controllers;

use App\Models\PayrollRun;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PayrollRunController extends Controller
{
    public function index()
    {
        return response()->json(
            PayrollRun::query()
                ->with('payPeriod')
                ->latest('created_at')
                ->paginate()
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'id' => ['required','uuid'],
            'pay_period_id' => ['required','uuid','exists:pay_periods,id'],
            'status' => ['required','in:draft,posted'],
        ]);

        $payrollRun = PayrollRun::create($data);

        return response()->json($payrollRun->load('payPeriod'), Response::HTTP_CREATED);
    }

    public function show(PayrollRun $payrollRun)
    {
        return response()->json($payrollRun->load('payPeriod'));
    }

    public function update(Request $request, PayrollRun $payrollRun)
    {
        $data = $request->validate([
            'pay_period_id' => ['sometimes','required','uuid','exists:pay_periods,id'],
            'status' => ['sometimes','required','in:draft,posted'],
        ]);

        $payrollRun->update($data);

        return response()->json($payrollRun->load('payPeriod'));
    }

    public function destroy(PayrollRun $payrollRun)
    {
        $payrollRun->delete();
        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}


