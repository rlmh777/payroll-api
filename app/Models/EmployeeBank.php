<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relation\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class EmployeeBank extends Model
{
    use HasUuids;

    protected $table = 'employee_bank';
    protected $primarykey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employeeId',
        'bankId',
        'accountNumber',
        'notes'
    ];

    public function employee(): BelongsTo {
        return $this->belongsTo(Employee::class);
    }

    public function bank(): BelongsTo {
        return $this->belongsTo(Bank::class);
    }

}
