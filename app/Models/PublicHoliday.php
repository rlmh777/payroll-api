<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PublicHoliday extends Model
{
    use HasUuids;

    protected $table = 'public_holiday';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $attributes = [
        'payMultiplier' => 1.5,
        'isActive' => true,
    ];

    protected $fillable = [
        'startDate',
        'endDate',
        'name',
        'payMultiplier',
        'isActive',
    ];

    protected $casts = [
        'startDate' => 'date:Y-m-d',
        'endDate' => 'date:Y-m-d',
        'payMultiplier' => 'decimal:2',
        'isActive' => 'boolean',
    ];
}
