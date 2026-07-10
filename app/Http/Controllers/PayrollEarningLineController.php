<?php

namespace App\Http\Controllers;

use App\Models\PayrollEarningLine;
use App\Models\PayrollRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayrollEarningLineController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PayrollEarningLine::query()
            ->with(['employee', 'department', 'earningCode', 'account', 'payrollRun']);

        if ($request->filled('payroll_run_id')) {
            $query->where('payroll_run_id', $request->input('payroll_run_id'));
        }

        if ($request->filled('employeeId')) {
            $query->where('employeeId', $request->input('employeeId'));
        }

        if ($request->filled('departmentId')) {
            $query->where('departmentId', $request->input('departmentId'));
        }

        if ($request->filled('payroll_earning_code_id')) {
            $query->where('payroll_earning_code_id', $request->input('payroll_earning_code_id'));
        }

        return response()->json($query->orderByDesc('created_at')->paginate((int) $request->get('per_page', 25)));
    }

    public function departmentReport(PayrollRun $payrollRun): JsonResponse
    {
        $rows = PayrollEarningLine::query()
            ->leftJoin('department', 'department.id', '=', 'payroll_earning_line.departmentId')
            ->join('payroll_earning_code', 'payroll_earning_code.id', '=', 'payroll_earning_line.payroll_earning_code_id')
            ->where('payroll_earning_line.payroll_run_id', $payrollRun->id)
            ->select([
                'payroll_earning_line.departmentId',
                DB::raw('COALESCE(department.name, \'Unassigned\') as department_name'),
                'payroll_earning_line.payroll_earning_code_id',
                'payroll_earning_code.code as earning_code',
                'payroll_earning_code.name as earning_name',
                DB::raw('SUM(payroll_earning_line.hours) as total_hours'),
                DB::raw('SUM(payroll_earning_line.amount) as total_amount'),
            ])
            ->groupBy(
                'payroll_earning_line.departmentId',
                DB::raw('COALESCE(department.name, \'Unassigned\')'),
                'payroll_earning_line.payroll_earning_code_id',
                'payroll_earning_code.code',
                'payroll_earning_code.name',
            )
            ->orderBy('department_name')
            ->orderBy('payroll_earning_code.sort_order')
            ->get();

        return response()->json([
            'payroll_run_id' => $payrollRun->id,
            'rows' => $rows,
        ]);
    }
}
