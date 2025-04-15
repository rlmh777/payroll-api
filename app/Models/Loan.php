<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Loan extends Model
{
    use HasUuids;

    protected $table = 'loan';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'note',
        'employeeId',
        'loanTypeId',
        'loanAmount',
        'interestType',
        'annualInterestRate',
        'loanPeriods',
        'optionalExtraPayment',
        'chartOfAccountId'
    ];

    public function employee(): BelongsTo
    {
        return $this->BelongsTo(Employee::class, 'employeeId');
    }

    public function loanType(): BelongsTo
    {
        return $this->BelongsTo(LoanType::class, 'loanTypeId');
    }

    public function chartOfAccount(): BelongsTo
    {
        return $this->BelongsTo(ChartOfAccount::class, 'chartOfAccountId');
    }
}
