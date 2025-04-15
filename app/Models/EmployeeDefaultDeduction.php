<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class EmployeeDefaultDeduction extends Model
{
    use HasUuids;

    protected $table = 'employee_default_deduction';
    protected $primarykey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employeeId',
        'paymentToId',
        'amount',
        'note',
        'frequencyId',
        'chartOfAccountId',
        'deductionTypeId'
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employeeId');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function payrateFrequency(): BelongsTo
    {
        return $this->belongsTo(PayrateFrequency::class);
    }

    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class);
    }

    public function deductionType(): BelongsTo
    {
        return $this->belongsTo(DeductionType::class);
    }

}
