<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class HistoricalEmployeeDeduction extends Model
{
    use HasUuids;

    protected $table = 'historical_employee_deduction';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employee_id',
        'employeeId',
        'payment_to_id',
        'paymentToId',
        'amount',
        'note',
        'payroll_run_id',
        'account_id',
        'accountId',
        'deduction_type_id',
        'deductionTypeId',
        'carryForwardShortfall',
        'priority',
        'departmentId',
    ];

    protected $casts = [
        'priority' => 'integer',
        'amount' => 'decimal:2',
        'carryForwardShortfall' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'payment_to_id');
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function deductionType(): BelongsTo
    {
        return $this->belongsTo(DeductionType::class, 'deduction_type_id');
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

    public function getPaymentToIdAttribute(): ?string
    {
        return $this->attributes['payment_to_id'] ?? null;
    }

    public function setPaymentToIdAttribute(?string $value): void
    {
        $this->attributes['payment_to_id'] = $value;
    }

    public function getAccountIdAttribute(): ?string
    {
        return $this->attributes['account_id'] ?? null;
    }

    public function setAccountIdAttribute(?string $value): void
    {
        $this->attributes['account_id'] = $value;
    }

    public function getDeductionTypeIdAttribute(): ?int
    {
        $value = $this->attributes['deduction_type_id'] ?? null;

        return $value === null ? null : (int) $value;
    }

    public function setDeductionTypeIdAttribute(null|int|string $value): void
    {
        $this->attributes['deduction_type_id'] = $value;
    }
}
