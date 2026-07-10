<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeSsBenefitStatus extends Model
{
    use HasUuids;

    protected $table = 'employee_ss_benefit_status';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employeeId',
        'is_receiving_benefit',
        'ss_benefit_type_id',
        'effective_from',
        'effective_to',
        'verified_at',
        'notes',
    ];

    protected $casts = [
        'is_receiving_benefit' => 'boolean',
        'effective_from' => 'date:Y-m-d',
        'effective_to' => 'date:Y-m-d',
        'verified_at' => 'date:Y-m-d',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employeeId');
    }

    public function benefitType(): BelongsTo
    {
        return $this->belongsTo(SsBenefitType::class, 'ss_benefit_type_id');
    }
}
