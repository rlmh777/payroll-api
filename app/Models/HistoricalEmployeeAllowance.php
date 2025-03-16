<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relation\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class HistoricalEmployeeAllowance extends Model
{
    use HasUuids;

    protected $table = 'historical_employee_allowance';
    protected $primarykey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employeeId',
        'amount',
        'note',
        'amount',
        'payrollId',
        'allowanceId',
        'chartOfAccountId'   
    ];
    public function employee(): BelongsTo {
        return $this->belongsTo(Employee::class);
    }

    public function allowance(): BelongsTo {
        return $this->belongsTo(Allowance::class);
    }

    public function payroll(): BelongsTo {
        return $this->belongsTo(Payroll::class);
    }

    public function chartOfAccount(): BelongsTo {
        return $this->belongsTo(ChartOfAccount::class);
    }

}
