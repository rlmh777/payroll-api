<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relation\BelongsTo;
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
        'phoneNumber',
        'amount',
        'note',
        'payrollId',
        'chartOfAccountId'   
    ];

    public function employee(): BelongsTo {
        return $this->belongsTo(Employee::class);
    }

    public function vendor(): BelongsTo {
        return $this->belongsTo(Vendor::class);
    }

    public function payroll(): BelongsTo {
        return $this->belongsTo(Payroll::class);
    }

    public function chartOfAccount(): BelongsTo {
        return $this->belongsTo(ChartOfAccount::class);
    }



    
}
