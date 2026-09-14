<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $connection = 'platform';

    protected $table = 'tenants';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'slug',
        'name',
        'database',
        'enabled',
        'meta',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'meta' => 'array',
    ];
}
