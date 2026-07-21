<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeHoursBankLedger extends Model
{
    use HasUuids;

    public const TYPE_ACCRUAL = 'accrual';
    public const TYPE_SHORTFALL = 'shortfall';
    public const TYPE_LEAVE_APPLY = 'leave_apply';
    public const TYPE_LEAVE_DEFICIT = 'leave_deficit';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_PAYROLL_SYNC = 'payroll_sync';

    protected $table = 'employee_hours_bank_ledger';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employee_id',
        'entry_date',
        'week_start_date',
        'payroll_run_id',
        'employee_leave_id',
        'entry_type',
        'hours_delta',
        'balance_after',
        'expected_hours',
        'worked_hours',
        'notes',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'week_start_date' => 'date',
        'hours_delta' => 'decimal:4',
        'balance_after' => 'decimal:4',
        'expected_hours' => 'decimal:4',
        'worked_hours' => 'decimal:4',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employeeLeave(): BelongsTo
    {
        return $this->belongsTo(EmployeeLeave::class, 'employee_leave_id');
    }
}
