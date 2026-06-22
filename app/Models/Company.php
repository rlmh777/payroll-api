<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ParagonIE\CipherSweet\BlindIndex;
use ParagonIE\CipherSweet\EncryptedRow;

class Company extends Model
{
    protected $table = 'company';
    protected $primaryKey = 'id';

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
        'localityId',
        'primaryColor',
        'secondaryColor',
    ];

    protected $attributes = [
        'primaryColor' => '#1976D2',
        'secondaryColor' => '#26A69A',
    ];

    public function locality(): BelongsTo
    {
        return $this->belongsTo(Locality::class, 'localityId');
    }

    public function companyBankAccounts(): HasMany
    {
        return $this->hasMany(CompanyBankAccount::class, 'companyId');
    }

    protected function socialSecurityNumber(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if (is_resource($value)) {
                    return stream_get_contents($value) ?: '';
                }

                return $value ?? '';
            },
        );
    }

    public function toArray(): array
    {
        $array = parent::toArray();

        foreach ($array as $key => $value) {
            if (is_resource($value)) {
                $array[$key] = stream_get_contents($value) ?: '';
            }
        }

        return $array;
    }

    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
    {
        $encryptedRow
            ->addField('legalName')
            ->addField('alias')
            ->addField('socialSecurityNumber')
            ->addField('taxIdentificationNumber')
            ->addField('phoneNumber1')
            ->addField('phoneNumber2')
            ->addField('email')
            ->addBlindIndex('legalName', new BlindIndex('legalNameIndex'))
            ->addBlindIndex('alias', new BlindIndex('aliasIndex'))
            ->addBlindIndex('socialSecurityNumber', new BlindIndex('socialSecurityNumberIndex'))
            ->addBlindIndex('taxIdentificationNumber', new BlindIndex('taxIdentificationNumberIndex'))
            ->addBlindIndex('phoneNumber1', new BlindIndex('phoneNumber1Index'))
            ->addBlindIndex('phoneNumber2', new BlindIndex('phoneNumber2Index'))
            ->addBlindIndex('email', new BlindIndex('emailIndex'));
    }
}

