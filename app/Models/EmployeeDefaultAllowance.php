<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDefaultAllowance extends Model
{
    use HasUuids;

    protected $table = 'employee_default_allowance';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $attributes = [
        'occurrence' => 'every_payroll',
    ];

    protected $fillable = [
        'employeeId',
        'allowanceId',
        'accountId',
        'note',
        'quantity',
        'unitAmount',
        'amount',
        'occurrence',
        'occurrenceCycleLength',
        'occurrenceCycleOffset',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'quantity' => 'decimal:4',
        'unitAmount' => 'decimal:2',
        'occurrenceCycleLength' => 'integer',
        'occurrenceCycleOffset' => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employeeId');
    }

    public function allowance(): BelongsTo
    {
        return $this->belongsTo(Allowance::class, 'allowanceId');
    }

    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accountId');
    }
}
