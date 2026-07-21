<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistoricalEmployeeAllowance extends Model
{
    use HasUuids;

    protected $table = 'historical_employee_allowance';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'employee_id',
        'employeeId',
        'amount',
        'quantity',
        'unitAmount',
        'note',
        'payroll_run_id',
        'allowance_id',
        'allowanceId',
        'account_id',
        'accountId',
        'departmentId',
        'taxableAmount',
        'ssSubjectAmount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'quantity' => 'decimal:4',
        'unitAmount' => 'decimal:2',
        'taxableAmount' => 'decimal:2',
        'ssSubjectAmount' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function allowance(): BelongsTo
    {
        return $this->belongsTo(Allowance::class, 'allowance_id');
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'departmentId');
    }

    public function getEmployeeIdAttribute(): ?string
    {
        return $this->attributes['employee_id'] ?? null;
    }

    public function setEmployeeIdAttribute(?string $value): void
    {
        $this->attributes['employee_id'] = $value;
    }

    public function getAllowanceIdAttribute(): ?string
    {
        return $this->attributes['allowance_id'] ?? null;
    }

    public function setAllowanceIdAttribute(?string $value): void
    {
        $this->attributes['allowance_id'] = $value;
    }

    public function getAccountIdAttribute(): ?string
    {
        return $this->attributes['account_id'] ?? null;
    }

    public function setAccountIdAttribute(?string $value): void
    {
        $this->attributes['account_id'] = $value;
    }
}
