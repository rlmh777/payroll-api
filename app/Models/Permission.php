<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Illuminate\Support\Str;

class Permission extends SpatiePermission
{
    use HasFactory;
    use HasUuids;
    protected $primaryKey = 'id';
    public $incrementing = false;

    protected $fillable = [
        'name',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(
            function ($model) {
                if (!$model->getKeyType()) {
                    $model->{$model->getKeyName()} = (string) Str::uuid();
                }
            }
        );
    }

    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }

}
