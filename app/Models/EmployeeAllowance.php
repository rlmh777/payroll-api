<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relation\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class EmployeeAllowance extends Model
{
    use HasUuids;

    protected $table = 'default_employee_allowance';
    protected $primarykey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employeeId',
        'allowanceId',
        'frequencyId',
        'chartOfAccountId',
        'note',
        'amount'
    ];

    public function employee(): BelongsTo {
        return $this->belongsTo(Employee::class);
    }

    public function allowance(): BelongsTo {
        return $this->belongsTo(Allowance::class);
    }

    public function payrateFrequency(): BelongsTo {
        return $this->belongsTo(PayrateFrequency::class);
    }

    public function chartOfAccount(): BelongsTo {
        return $this->belongsTo(ChartOfAccount::class);
    }
}
