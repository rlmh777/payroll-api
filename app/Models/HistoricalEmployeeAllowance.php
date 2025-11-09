<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relation\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class HistoricalEmployeeAllowance extends Model
{
    use HasUuids;

    protected $table = 'historical_employee_allowance';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employeeId',
        'amount',
        'note',
        'amount',
        'payroll_run_id',
        'allowanceId',
        'accountId'   
    ];
    public function employee(): BelongsTo {
        return $this->belongsTo(Employee::class);
    }

    public function allowance(): BelongsTo {
        return $this->belongsTo(Allowance::class);
    }

    public function payrollRun(): BelongsTo {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function chartOfAccount(): BelongsTo {
        return $this->belongsTo(Account::class, 'accountId');
    }

}
