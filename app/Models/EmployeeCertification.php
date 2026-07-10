<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeCertification extends Model
{
    use HasUuids;

    protected $table = 'employee_certification';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employeeId',
        'name',
        'issuingOrganization',
        'credentialId',
        'issuedOn',
        'expiresOn',
        'notes',
    ];

    protected $casts = [
        'issuedOn' => 'date:Y-m-d',
        'expiresOn' => 'date:Y-m-d',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employeeId');
    }
}
