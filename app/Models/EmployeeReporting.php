<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeReporting extends Model
{
    use HasUuids;

    protected $table = 'employee_reporting';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'supervisor_id',
        'subordinate_id',
        'reporting_method',
        'effective_date',
        'is_active',
    ];

    protected $casts = [
        'effective_date' => 'date:Y-m-d',
        'is_active' => 'boolean',
    ];

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }

    public function subordinate(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'subordinate_id');
    }
}
