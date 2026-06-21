<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class EmployeeDefaultDeduction extends Model
{
    use HasUuids;

    protected $table = 'employee_default_deduction';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employeeId',
        'bankId',
        'accountNumber',
        'amount',
        'note',
        'frequencyId',
        'accountId',
        'deductionTypeId',
        'allowPartialDeduction',
        'applicationRule',
        'priority',
    ];

    protected $casts = [
        'allowPartialDeduction' => 'boolean',
        'priority' => 'integer',
        'amount' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employeeId');
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bankId');
    }

    public function payrateFrequency(): BelongsTo
    {
        return $this->belongsTo(PayrateFrequency::class, 'frequencyId');
    }

    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accountId');
    }

    public function deduction(): BelongsTo
    {
        return $this->belongsTo(DeductionType::class, 'deductionTypeId');
    }

    public function deductionType(): BelongsTo
    {
        return $this->belongsTo(DeductionType::class, 'deductionTypeId');
    }
}
