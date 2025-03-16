<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactType extends Model
{
    protected $table = 'contact_type';
    protected $primarykey = 'id';

    protected $fillable = [
        'name'
    ];
}
