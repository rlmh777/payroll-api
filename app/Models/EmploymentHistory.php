<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relation\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;
use ParagonIE\CipherSweet\EncryptedRow;
use Spatie\LaravelCipherSweet\Concerns\UsesCipherSweet;

class EmploymentHistory extends Model
{
    use HasUuids;

    protected $table = 'employment_history';
    protected $primarykey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employeeId',
        'employerName',
        'positionHeld',
        'from',
        'to',
        'note'
    ];

    public function employee(): BelongsTo {
        return $this->belongsTo(Employee::class);
    }

    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
    {
        $encryptedRow
            // add the columns you want to encrypt the values ​​for
            ->addField('employerName')

            // add a blind index for each column you want to search
            ->addBlindIndex('employerName', new BlindIndex('employerNameIndex'));

    }
}
