<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeHoursBank extends Model
{
    use HasUuids;

    protected $table = 'employee_hours_bank';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employee_id',
        'balance_hours',
    ];

    protected $casts = [
        'balance_hours' => 'decimal:4',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(EmployeeHoursBankLedger::class, 'employee_id', 'employee_id');
    }
}
