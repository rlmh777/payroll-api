<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ParagonIE\CipherSweet\BlindIndex;
use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;
use ParagonIE\CipherSweet\EncryptedRow;
use Spatie\LaravelCipherSweet\Concerns\UsesCipherSweet;
use ParagonIE\CipherSweet\Constants;

class Employee extends Model implements CipherSweetEncrypted
{
    use HasUuids;
    use UsesCipherSweet;

    protected $table = 'employee';
    protected $primaryKey = 'id';
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


    public function locality(): BelongsTo
    {
        return $this->belongsTo(Locality::class);
    }

    public function honorific(): BelongsTo
    {
        return $this->belongsTo(Honorific::class);
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

    public function defaultPayrateFrequency(): BelongsTo
    {
        return $this->belongsTo(PayrateFrequency::class, 'payrateFrequencyId');
    }

    public function employeeWorkPermit(): HasMany
    {
        return $this->hasMany(EmployeeWorkPermit::class);
    }

    public function employmentHistory(): HasMany
    {
        return $this->hasMany(EmploymentHistory::class);
    }

    public function allowances(): HasMany
    {
        return $this->hasMany(EmployeeAllowance::class, 'employeeId');
    }

    public function employeeBanks(): HasMany
    {
        return $this->hasMany(EmployeeBank::class, 'employeeId');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(EmployeeContact::class, 'employeeId');
    }

    public function employeeDefaultDeductions(): HasMany
    {
        return $this->hasMany(EmployeeDefaultDeduction::class, 'employeeId');
    }

    public function employmentDetails(): HasMany
    {
        return $this->hasMany(EmploymentDetail::class, 'employeeId');
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class, 'employeeId');
    }

    public function payrolls(): HasMany
    {
        return $this->hasMany(Payroll::class, 'employeeId');
    }

    public function historicalDeductions(): HasMany
    {
        return $this->hasMany(HistoricalEmployeeDeduction::class);
    }

    public function qualifications(): HasMany
    {
        return $this->hasMany(Qualification::class, 'employeeId');
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(TrackEmployeeLeave::class, 'employeeId');
    }

    public function paymentMethods(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'paymentMethodId');
    }

    public function historicalAllowances(): HasMany
    {
        return $this->hasMany(HistoricalEmployeeAllowance::class);
    }

    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
    {
        $encryptedRow
            // add the columns you want to encrypt the values ​​for
            ->addField('socialSecurityNumber', Constants::TYPE_OPTIONAL_TEXT)
            ->addField('taxIdentificationNumber', Constants::TYPE_OPTIONAL_TEXT)
            ->addField('passportNumber', Constants::TYPE_OPTIONAL_TEXT)
            ->addField('votersId', Constants::TYPE_OPTIONAL_TEXT)
            ->addField('firstName')
            ->addField('middleName', Constants::TYPE_OPTIONAL_TEXT)
            ->addField('lastName')
            ->addField('maidenName', Constants::TYPE_OPTIONAL_TEXT)
            ->addField('notes', Constants::TYPE_OPTIONAL_TEXT)
            ->addField('health', Constants::TYPE_OPTIONAL_TEXT)
            ->addField('picturePath', Constants::TYPE_OPTIONAL_TEXT)

            // add a blind index for each column you want to search
            ->addBlindIndex('socialSecurityNumber', new BlindIndex('socialSecurityNumberIndex'))
            ->addBlindIndex('taxIdentificationNumber', new BlindIndex('taxIdentificationNumberIndex'))
            ->addBlindIndex('passportNumber', new BlindIndex('passportNumberIndex'))
            ->addBlindIndex('firstName', new BlindIndex('firstNameIndex'))
            ->addBlindIndex('middleName', new BlindIndex('middleNameIndex'))
            ->addBlindIndex('lastName', new BlindIndex('lastNameIndex'))
            ->addBlindIndex('maidenName', new BlindIndex('maidenNameIndex'))
            ->addBlindIndex('notes', new BlindIndex('notesIndex'))
            ->addBlindIndex('health', new BlindIndex('healthIndex'))
            ->addBlindIndex('picturePath', new BlindIndex('picturePathIndex'))
            ->addBlindIndex('votersId', new BlindIndex('votersIdIndex'));

    }



}
