<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollEarningLine extends Model
{
    use HasUuids;

    protected $table = 'payroll_earning_line';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'payroll_run_id',
        'employeeId',
        'payroll_id',
        'departmentId',
        'payroll_earning_code_id',
        'hours',
        'rate',
        'amount',
        'accountId',
        'is_taxable',
        'is_ss_subject',
        'source_type',
        'source_id',
        'note',
    ];

    protected $casts = [
        'hours' => 'decimal:2',
        'rate' => 'decimal:4',
        'amount' => 'decimal:2',
        'is_taxable' => 'boolean',
        'is_ss_subject' => 'boolean',
    ];

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employeeId');
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class, 'payroll_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'departmentId');
    }

    public function earningCode(): BelongsTo
    {
        return $this->belongsTo(PayrollEarningCode::class, 'payroll_earning_code_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accountId');
    }
}
