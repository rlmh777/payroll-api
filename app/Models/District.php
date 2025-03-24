<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class District extends Model
{
    use HasUuids;

    protected $table = 'district';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'countryId'
    ];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'countryId');
    }

    public function localities(): HasMany
    {
        return $this->hasMany(Locality::class);
    }
}
