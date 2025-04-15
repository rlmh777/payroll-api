<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relation\HasMany;

class Institution extends Model
{
    protected $table = 'institution';
    protected $primarykey = 'id';

    protected $fillable = [
        'name'
    ];

    public function qualifications(): HasMany {
        return $this->hasMany(Qualification::class);
    }

}
