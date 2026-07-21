<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\Account;
use App\Models\Allowance;
use App\Models\DeductionType;
use App\Models\Employee;
use App\Models\HistoricalEmployeeAllowance;
use App\Models\HistoricalEmployeeDeduction;
use App\Models\PayrollRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PayrollRunAllowanceDeductionImportController extends Controller
{
    public function preview(Request $request, PayrollRun $payrollRun): JsonResponse
    {
        $rows = $this->validatedRows($request);

        return response()->json($this->buildPreview($payrollRun, $rows));
    }

    public function confirm(Request $request, PayrollRun $payrollRun): JsonResponse
    {
        if (strtolower((string) $payrollRun->status) !== 'draft') {
            return response()->json([
                'message' => 'Processed payroll runs cannot receive imported allowances or deductions.',
            ], 422);
        }

        $rows = $this->validatedRows($request);
        $preview = $this->buildPreview($payrollRun, $rows);

        if (($preview['errorCount'] ?? 0) > 0) {
            return response()->json([
                'message' => 'Resolve import errors before posting records.',
                ...$preview,
            ], 422);
        }

        $allowance = Allowance::query()->firstOrCreate(
            ['name' => 'Payroll Import'],
            [
                'isTaxable' => true,
                'isSocialSecurityDeductable' => true,
                'note' => 'Created automatically for payroll run imports.',
                'defaultAmount' => 0,
            ],
        );
        $deductionType = DeductionType::query()->firstOrCreate(
            ['name' => 'Payroll Import Deduction'],
            [
                'note' => 'Created automatically for payroll run imports.',
                'defaultAmount' => 0,
            ],
        );

        $inserted = DB::transaction(function () use ($preview, $payrollRun, $allowance, $deductionType) {
            $allowanceCount = 0;
            $deductionCount = 0;

            foreach ($preview['employees'] as $employee) {
                foreach ($employee['details'] as $detail) {
                    $amount = round((float) $detail['amount'], 2);
                    $payload = [
                        'employee_id' => $employee['employeeId'],
                        'amount' => $amount,
                        'note' => sprintf(
                            '%s import %s on %s (qty %s, rate %s)',
                            $detail['kind'] === 'deduction' ? 'Deduction' : 'Allowance',
                            $detail['code'],
                            $detail['date'],
                            $detail['quantity'],
                            $detail['rate'],
                        ),
                        'payroll_run_id' => $payrollRun->id,
                        'account_id' => $detail['accountId'],
                    ];

                    if ($detail['kind'] === 'deduction') {
                        HistoricalEmployeeDeduction::query()->create([
                            ...$payload,
                            'payment_to_id' => null,
                            'deduction_type_id' => $deductionType->id,
                            'carryForwardShortfall' => 0,
                            'priority' => 0,
                        ]);
                        $deductionCount++;
                    } else {
                        $isTaxable = (bool) ($allowance->isTaxable ?? true);
                        $isSsSubject = (bool) ($allowance->isSocialSecurityDeductable ?? true);

                        HistoricalEmployeeAllowance::query()->create([
                            ...$payload,
                            'allowance_id' => $allowance->id,
                            'quantity' => round((float) $detail['quantity'], 4),
                            'unitAmount' => round((float) ($detail['rate'] ?? 0), 2),
                            'taxableAmount' => $isTaxable ? $amount : 0,
                            'ssSubjectAmount' => $isSsSubject ? $amount : 0,
                        ]);
                        $allowanceCount++;
                    }
                }
            }

            return [
                'allowances' => $allowanceCount,
                'deductions' => $deductionCount,
                'total' => $allowanceCount + $deductionCount,
            ];
        });

        return response()->json([
            'message' => 'Payroll import posted.',
            'inserted' => $inserted,
            ...$preview,
        ], 201);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function validatedRows(Request $request): array
    {
        $validated = $request->validate([
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.employeeIdentifier' => ['required', 'string'],
            'rows.*.employeeName' => ['nullable', 'string'],
            'rows.*.code' => ['nullable', 'string'],
            'rows.*.accountId' => ['nullable', 'uuid', 'exists:accounts,id'],
            'rows.*.date' => ['required', 'date'],
            'rows.*.quantity' => ['required', 'numeric'],
            'rows.*.rate' => ['nullable', 'numeric'],
            'rows.*.amount' => ['required', 'numeric', 'min:0'],
        ]);

        return $validated['rows'];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function buildPreview(PayrollRun $payrollRun, array $rows): array
    {
        $employeeCache = [];
        $accountCache = [];
        $employees = [];
        $errorCount = 0;
        $allowanceTotal = 0.0;
        $deductionTotal = 0.0;

        foreach ($rows as $index => $row) {
            $employeeIdentifier = trim((string) $row['employeeIdentifier']);
            $code = trim((string) ($row['code'] ?? ''));
            $accountId = isset($row['accountId']) ? trim((string) $row['accountId']) : '';
            $quantity = (float) $row['quantity'];
            $rate = (float) ($row['rate'] ?? 0);
            $amount = round((float) $row['amount'], 2);
            $kind = $quantity < 0 ? 'deduction' : 'allowance';
            $employee = $employeeCache[$employeeIdentifier] ??= $this->resolveEmployee($employeeIdentifier);
            $accountCacheKey = $accountId !== '' ? 'id:'.$accountId : 'code:'.$code;
            $account = $accountCache[$accountCacheKey] ??= $this->resolveAccount($code, $accountId);
            $errors = [];

            if (!$employee) {
                $errors[] = "Employee '{$employeeIdentifier}' was not found.";
            }

            if (!$account) {
                $errors[] = $code === ''
                    ? 'Select an account for rows without account codes.'
                    : "Account code '{$code}' was not found.";
            }

            if ($amount <= 0) {
                $errors[] = 'Amount must be greater than zero.';
            }

            $errorCount += count($errors);
            $employeeKey = $employee?->id ? (string) $employee->id : 'unresolved:'.$employeeIdentifier;

            if (!isset($employees[$employeeKey])) {
                $employees[$employeeKey] = [
                    'employeeId' => $employee?->id ? (string) $employee->id : null,
                    'employeeIdentifier' => $employeeIdentifier,
                    'employeeName' => $employee
                        ? trim(sprintf('%s %s', $employee->firstName ?? '', $employee->lastName ?? ''))
                        : ($row['employeeName'] ?? null),
                    'allowanceTotal' => 0.0,
                    'deductionTotal' => 0.0,
                    'recordCount' => 0,
                    'errorCount' => 0,
                    'details' => [],
                ];
            }

            if ($kind === 'deduction') {
                $employees[$employeeKey]['deductionTotal'] = round($employees[$employeeKey]['deductionTotal'] + $amount, 2);
                $deductionTotal = round($deductionTotal + $amount, 2);
            } else {
                $employees[$employeeKey]['allowanceTotal'] = round($employees[$employeeKey]['allowanceTotal'] + $amount, 2);
                $allowanceTotal = round($allowanceTotal + $amount, 2);
            }

            $employees[$employeeKey]['recordCount']++;
            $employees[$employeeKey]['errorCount'] += count($errors);
            $employees[$employeeKey]['details'][] = [
                'rowNumber' => $index + 1,
                'kind' => $kind,
                'code' => $code !== '' ? $code : ($account?->code1 ?? $account?->name ?? ''),
                'date' => (string) $row['date'],
                'quantity' => $quantity,
                'rate' => $rate,
                'amount' => $amount,
                'accountId' => $account?->id ? (string) $account->id : null,
                'accountName' => $account?->name,
                'errors' => $errors,
            ];
        }

        return [
            'payrollRunId' => (string) $payrollRun->id,
            'recordCount' => count($rows),
            'employeeCount' => count($employees),
            'errorCount' => $errorCount,
            'allowanceTotal' => round($allowanceTotal, 2),
            'deductionTotal' => round($deductionTotal, 2),
            'employees' => array_values($employees),
        ];
    }

    private function resolveEmployee(string $identifier): ?Employee
    {
        return Employee::query()
            ->where(function ($query) use ($identifier) {
                if (Str::isUuid($identifier)) {
                    $query->where('id', $identifier);
                }

                $query
                    ->orWhere('code', $identifier)
                    ->orWhere('internalId1', $identifier)
                    ->orWhere('internalId2', $identifier);
            })
            ->first();
    }

    private function resolveAccount(string $code, ?string $accountId = null): ?Account
    {
        if ($accountId) {
            return Account::query()->find($accountId);
        }

        if ($code === '') {
            return null;
        }

        $normalized = mb_strtolower($code);

        return Account::query()
            ->whereRaw('LOWER("code1") = ?', [$normalized])
            ->orWhereRaw('LOWER("code2") = ?', [$normalized])
            ->orWhereRaw('LOWER("name") = ?', [$normalized])
            ->first();
    }
}
