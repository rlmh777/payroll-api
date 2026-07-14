<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\CitizenshipStatus;
use App\Models\Country;
use App\Models\Gender;
use App\Models\Honorific;
use App\Models\Locality;

class Person extends Model
{
    use HasUuids;

    public const ATTRIBUTE_KEYS = [
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
        'socialSecurityExpirationDate',
        'taxIdentificationNumber',
        'passportNumber',
        'votersId',
        'citizenshipStatusId',
        'nationalityId',
        'notes',
        'picturePath',
        'health',
        'unionMembership',
    ];

    protected $table = 'person';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = self::ATTRIBUTE_KEYS;

    protected $casts = [
        'birthdate' => 'date:Y-m-d',
        'socialSecurityExpirationDate' => 'date:Y-m-d',
    ];

    public function locality(): BelongsTo
    {
        return $this->belongsTo(Locality::class, 'localityId');
    }

    public function honorific(): BelongsTo
    {
        return $this->belongsTo(Honorific::class, 'honorificId');
    }

    public function gender(): BelongsTo
    {
        return $this->belongsTo(Gender::class, 'genderId');
    }

    public function citizenshipStatus(): BelongsTo
    {
        return $this->belongsTo(CitizenshipStatus::class, 'citizenshipStatusId');
    }

    public function nationality(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'nationalityId');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(\App\Modules\Hr\Models\Employee::class, 'person_id');
    }
}
