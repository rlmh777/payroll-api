<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relation\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class EmployeeWorkPermit extends Model
{
    use HasUuids;

    protected $table = 'employee_work_permit';
    protected $primarykey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employeeId',
        'workPermitNumber',
        'issued',
        'expires',
        'socialSecurityNumber'
    ];

    public function employee(): BelongsTo {
        return $this->belongsTo(Employee::class);
    }

    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
    {
        $encryptedRow
            // add the columns you want to encrypt the values ​​for
            ->addField('socialSecurityNumber')
            ->addField('workPermitNumber')
            

            // add a blind index for each column you want to search
            ->addBlindIndex('socialSecurityNumber', new BlindIndex('socialSecurityNumberIndex'))
            ->addBlindIndex('workPermitNumber', new BlindIndex('workPermitNumberIndex'));
    }

}
