<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Calendar extends Model
{
    use HasUuids;

    protected $table = 'calendar';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'calendar_group_id',
        'date',
        'type',
        'description',
        'rate',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'rate' => 'decimal:2',
    ];
}
