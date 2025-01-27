<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relation\BelongsTo;
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
        'phoneNumber',
        'amount',
        'note',
        'frequencyId',
        'chartOfAccountId'
    ];

    public function employee(): BelongsTo {
        return $this->belongsTo(Employee::class);
    }

    public function vendor(): BelongsTo {
        return $this->belongsTo(Vendor::class);
    }

    public function payrateFrequency(): BelongsTo {
        return $this->belongsTo(PayrateFrequency::class);
    }

    public function chartOfAccount(): BelongsTo {
        return $this->belongsTo(ChartOfAccount::class);
    }

}
