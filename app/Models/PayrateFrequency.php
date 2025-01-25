<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrateFrequency extends Model
{
    protected $table = 'payrate_frequency';
    protected $primarykey = 'id';

    protected $fillable = [
        'name'
    ];
}
