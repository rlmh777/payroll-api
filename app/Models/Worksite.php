<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relation\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Worksite extends Model
{
    use HasUuids;

    protected $table = 'worksite';
    protected $primarykey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'address1',
        'address2',
        'localityId'
    ];

    public function locality(): BelongsTo {
        return $this->belongsTo(Locality::class);
    }
}
