<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relation\BelongsTo;
use Illuminate\Database\Eloquent\Relation\HasMany;
use Illuminate\Database\Eloquent\Relation\HasManyThrough;
use ParagonIE\CipherSweet\BlindIndex;
use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;
use ParagonIE\CipherSweet\EncryptedRow;
use Spatie\LaravelCipherSweet\Concerns\UsesCipherSweet;

class Employee extends Model implements CipherSweetEncrypted
{
    use HasUuids;
    use UsesCipherSweet;

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
        'payrateFrequencyId',
        'paymentMethodId',
        'notes',
        'picturePath',
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

    public function employeeWorkPermit(): HasMany {
        return $this->hasMany(EmployeeWorkPermit::class);
    }

    public function employmentHistory(): HasMany {
        return $this->hasMany(EmployeeHistory::class);
    }

    public function allowances(): HasMany {
        return $this->hasMany(EmployeeAllowance::class);
    }

    public function employeeBanks(): HasMany {
        return $this->hasMany(EmployeeBank::class);
    }

    public function contacts(): HasMany {
        return $this->hasMany(EmployeeContact::class);
    }

    public function employeeDefaultDeductions(): HasMany {
        return $this->hasMany(EmployeeDefaultDeduction::class);
    }

    public function employmentDetails(): HasMany {
        return $this->hasMany(EmploymentDetail::class);
    }

    public function loans(): HasMany {
        return $this->hasMany(Loan::class);
    }

    public function payrolls(): HasMany {
        return $this->hasMany(Payroll::class);
    }

    public function historicalDeductions(): HasMany {
        return $this->hasMany(HistoricalEmployeeDeduction::class);
    }

    public function qualifications(): HasMany {
        return $this->hasMany(Qualification::class);
    }

    public function leaves(): HasMany {
        return $this->hasMany(TrackEmployeeLeave::class);
    }

    public function paymentMethods(): BelongsTo {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function historicalAllowances(): HasMany {
        return $this->hasMany(HistoricalEmployeeAllowance::class);
    }

    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
    {
        $encryptedRow
            // add the columns you want to encrypt the values ​​for
            ->addField('socialSecurityNumber')
            ->addField('taxIdentificationNumber')
            ->addField('passportNumber')
            ->addField('votersId')
            // add a blind index for each column you want to search
            ->addBlindIndex('socialSecurityNumber', new BlindIndex('socialSecurityNumberIndex'))
            ->addBlindIndex('taxIdentificationNumber', new BlindIndex('taxIdentificationNumberIndex'))
            ->addBlindIndex('passportNumber', new BlindIndex('passportNumberIndex'))
            ->addBlindIndex('votersId', new BlindIndex('votersIdIndex'));

    }

}
