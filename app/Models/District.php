<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relation\BelongsTo;
use Illuminate\Database\Eloquent\Relation\HasMany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Districts extends Model
{
    use HasUuids;

    protected $table = 'district';
    protected $primarykey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'countryId'
    ];

    public function country(): BelongsTo {
        return $this->belongsTo(Country::class);
    }

    public function localities():HasMany {
        return $this->hasMany(Locality::class);
    }
}
