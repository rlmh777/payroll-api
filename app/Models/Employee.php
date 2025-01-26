<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relation\BelongsTo;
use Illuminate\Database\Eloquent\Relation\HasMany;


class Employee extends Model
{
    use HasUuids;

    protected $table = 'employee';
    protected $primarykey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'code',
        'internalId1',
        'internalId2',
        'honorificId',
        'firstName',
        'middleName',
        'lastName',
        'maidenName',
        'birthdate',
        'address1',
        'address2',
        'localityId',
        'phone',
        'email',
        'genderId',
        'socialSecurityNumber',
        'taxIdentificationNumber',
        'passportNumber',
        'votersId',
        'citizenshipStatusId',
        'nationalityId',
        'defaultPayrateFrequencyId',
        'hourlyrate',
        'annualSalary',
        'notes',
        'picturePath',
        'statusId',
        'health',
        'unionMembership'
    ];


    public function locality(): BelongsTo {
        return $this->belongsTo(Locality::class);
    }

    public function honorific(): BelongsTo {
        return $this->belongsTo(Honorific::class);
    }

    public function gender(): BelongsTo {
        return $this->belongsTo(Gender::class);
    }

    public function citizenshipStatus(): BelongsTo {
        return $this->belongsTo(CitizenshipSatus::class);
    }

    public function nationality(): BelongsTo {
        return $this->belongsTo(Country::class);
    }

    public function defaultPayrateFrequency(): BelongsTo {
        return $this->belongsTo(PayrateFrequency::class);
    }

    public function employmentStatus(): BelongsTo {
        return $this->belongsTo(EmploymentStatus::class);
    }

    public function employeeWorkPermit(): HasMany {
        return $this->hasMany(EmployeeWorkPermit::class);
    }

    public function employmentHistory(): HasMany {
        return $this->hasMany(EmployeeHistory::class);
    }

}
