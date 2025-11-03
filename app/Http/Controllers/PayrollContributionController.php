<?php

namespace App\Http\Controllers;

use App\Models\PayrollContribution;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PayrollContributionController extends Controller
{
    public function index()
    {
        return response()->json(
            PayrollContribution::query()
                ->with(['payrollRun', 'employee', 'account'])
                ->latest('created_at')
                ->paginate()
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'id' => ['required','uuid'],
            'payroll_run_id' => ['required','uuid','exists:payroll_runs,id'],
            'employee_id' => ['required','uuid','exists:employees,id'],
            'account_id' => ['required','uuid','exists:accounts,id'],
            'amount' => ['required','numeric','min:0'],
        ]);

        $contribution = PayrollContribution::create($data);

        return response()->json($contribution->load(['payrollRun', 'employee', 'account']), Response::HTTP_CREATED);
    }

    public function show(PayrollContribution $payrollContribution)
    {
        return response()->json($payrollContribution->load(['payrollRun', 'employee', 'account']));
    }

    public function update(Request $request, PayrollContribution $payrollContribution)
    {
        $data = $request->validate([
            'payroll_run_id' => ['sometimes','required','uuid','exists:payroll_runs,id'],
            'employee_id' => ['sometimes','required','uuid','exists:employees,id'],
            'account_id' => ['sometimes','required','uuid','exists:accounts,id'],
            'amount' => ['sometimes','required','numeric','min:0'],
        ]);

        $payrollContribution->update($data);

        return response()->json($payrollContribution->load(['payrollRun', 'employee', 'account']));
    }

    public function destroy(PayrollContribution $payrollContribution)
    {
        $payrollContribution->delete();
        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}


