<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
        'street'
    ];

}
