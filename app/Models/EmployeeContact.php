<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class EmployeeContact extends Model
{
    use HasUuids;

    protected $table = 'employee_contact';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'firstName',
        'middleName',
        'lastName',
        'phoneNumber1',
        'phoneNumber2',
        'email',
        'address1',
        'address2',
        'localityId',
        'relationshipId',
        'employeeId',
        'isDependent',
        'isProfessionalReference'
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employeeId');
    }

    public function relationship(): BelongsTo
    {
        return $this->belongsTo(Relationship::class, 'relationshipId');
    }

    public function locality(): BelongsTo
    {
        return $this->belongsTo(Locality::class, 'localityId');
    }
}
