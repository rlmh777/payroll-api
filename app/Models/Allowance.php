<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Allowance extends Model
{
    use HasUuids;

    protected $table = 'allowance';
    protected $primarykey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'isTaxable',
        'isSocialSecurityDeductable',
        'note',
        'defaultAmount'
    ];
}
