<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relation\BelongsTo;
use ParagonIE\CipherSweet\BlindIndex;
use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;
use ParagonIE\CipherSweet\EncryptedRow;
use Spatie\LaravelCipherSweet\Concerns\UsesCipherSweet;
use ParagonIE\CipherSweet\Constants;

class Company extends Model
{
    protected $table = 'company';
    protected $primarykey = 'id';

    protected $fillable = [
        'legalName',
        'alias',
        'socialSecurityNumber',
        'taxIdentificationNumber',
        'logoPath',
        'phoneNumber1',
        'phoneNumber2',
        'email',
        'street',
        'localityId'
    ];

    public function locality(): BelongsTo
    {
        return $this->belongsTo(Locality::class);
    }

    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
    {
        $encryptedRow
            // add the columns you want to encrypt the values ​​for
            ->addField('legalName')
            ->addField('alias')
            ->addField('socialSecurityNumber')
            ->addField('taxIdentificationNumber')
            ->addField('phoneNumber1')
            ->addField('phoneNumber2')
            ->addField('email')

            // add a blind index for each column you want to search
            ->addBlindIndex('legalName', new BlindIndex('legalNameIndex'))
            ->addBlindIndex('alias', new BlindIndex('aliasIndex'))
            ->addBlindIndex('socialSecurityNumber', new BlindIndex('socialSecurityNumberIndex'))
            ->addBlindIndex('taxIdentificationNumber', new BlindIndex('taxIdentificationNumberIndex'))
            ->addBlindIndex('phoneNumber1', new BlindIndex('phoneNumber1Index'))
            ->addBlindIndex('phoneNumber2', new BlindIndex('phoneNumber2Index'))
            ->addBlindIndex('email', new BlindIndex('emailIndex'));
    }

}
