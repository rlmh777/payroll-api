<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class HistoricalEmployeeDeduction extends Model
{
    use HasUuids;

    protected $table = 'historical_employee_deduction';
    protected $primarykey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employeeId',
        'paymentToId',
        'amount',
        'note',
        'payrollId',
        'chartOfAccountId',
        'deductionTypeId'
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employeeId');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'paymentToId');
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class, 'payrollId');
    }

    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'chartOfAccountId');
    }

    public function deductionType(): BelongsTo
    {
        return $this->belongsTo(DeductionType::class, 'deductionTypeId');
    }

}
