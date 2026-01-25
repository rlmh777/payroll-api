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
                ->with('payPeriodSchedule')
                ->latest('created_at')
                ->paginate()
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'id' => ['required','uuid'],
            'pay_period_schedule_id' => ['required','uuid','exists:pay_period_schedule,id'],
            'status' => ['required','in:draft,posted'],
        ]);

        $payrollRun = PayrollRun::create($data);

        return response()->json($payrollRun->load('payPeriodSchedule'), Response::HTTP_CREATED);
    }

    public function show(PayrollRun $payrollRun)
    {
        return response()->json($payrollRun->load('payPeriodSchedule'));
    }

    public function update(Request $request, PayrollRun $payrollRun)
    {
        $data = $request->validate([
            'pay_period_schedule_id' => ['sometimes','required','uuid','exists:pay_period_schedule,id'],
            'status' => ['sometimes','required','in:draft,posted'],
        ]);

        $payrollRun->update($data);

        return response()->json($payrollRun->load('payPeriodSchedule'));
    }

    public function destroy(PayrollRun $payrollRun)
    {
        $payrollRun->delete();
        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}


