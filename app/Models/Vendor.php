<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relation\BelongsTo;
use Illuminate\Database\Eloquent\Relation\HasMany;
use Illuminate\Database\Eloquent\Model;
use ParagonIE\CipherSweet\BlindIndex;
use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;
use ParagonIE\CipherSweet\EncryptedRow;
use Spatie\LaravelCipherSweet\Concerns\UsesCipherSweet;

class Vendor extends Model
{
    use HasUuids;

    protected $table = 'vendor';
    protected $primarykey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'phone',
        'email'
        'bankId',
        'accountNumber'
    ];

    public function bank(): BelongsTo {
        return $this->belongsTo(Bank::class);
    }

    public function deductions(): HasMany {
        return $this->hasMany(EmployeeDefaultDeduction::class);
    }

    public function historicalDeductions(): HasMany {
        return $this->hasMany(HistoricalEmployeeDeduction::class);
    }

    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
    {
        $encryptedRow
            // add the columns you want to encrypt the values ​​for
            ->addField('name')
            ->addField('phone')
            ->addField('email')
            ->addField('accountNumber')

            // add a blind index for each column you want to search
            ->addBlindIndex('name', new BlindIndex('nameIndex'))
            ->addBlindIndex('phone', new BlindIndex('phoneIndex'))
            ->addBlindIndex('email', new BlindIndex('emailIndex'))
            ->addBlindIndex('accountNumber', new BlindIndex('accountNumberIndex'));

    }

}