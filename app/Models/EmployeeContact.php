<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;
use ParagonIE\CipherSweet\EncryptedRow;
use Spatie\LaravelCipherSweet\Concerns\UsesCipherSweet;

class EmployeeContact extends Model
{
    use HasUuids;

    protected $table = 'employee_contact';
    protected $primarykey = 'id';
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
        return $this->belongsTo(Relationship::class);
    }

    public function locality(): BelongsTo
    {
        return $this->belongsTo(Locality::class);
    }

    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
    {
        $encryptedRow
            // add the columns you want to encrypt the values ​​for
            ->addField('firstName')
            ->addField('middleName')
            ->addField('lastName')
            ->addField('phoneNumber1')
            ->addField('phoneNumber2')
            ->addField('email')

            // add a blind index for each column you want to search
            ->addBlindIndex('firstName', new BlindIndex('firstNameIndex'))
            ->addBlindIndex('middleName', new BlindIndex('middleNameIndex'))
            ->addBlindIndex('lastName', new BlindIndex('lastNameIndex'))
            ->addBlindIndex('phoneNumber1', new BlindIndex('phoneNumber1Index'))
            ->addBlindIndex('phoneNumber2', new BlindIndex('phoneNumber2Index'))
            ->addBlindIndex('email', new BlindIndex('emailIndex'));

    }
}
