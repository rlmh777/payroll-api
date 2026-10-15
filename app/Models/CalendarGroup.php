<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CalendarGroup extends Model
{
    use HasUuids;

    protected $table = 'calendar_groups';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'key',
        'name',
        'color',
    ];
}
