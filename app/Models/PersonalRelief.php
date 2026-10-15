<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PersonalRelief extends Model
{
    use HasUuids;

    protected $table = 'personal_relief';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'startRange',
        'endRange',
        'personalRelief',
    ];

    protected $casts = [
        'startRange' => 'decimal:2',
        'endRange' => 'decimal:2',
        'personalRelief' => 'decimal:2',
    ];
}

