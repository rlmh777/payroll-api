<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FormAccessForm extends Model
{
    use HasUuids;

    public const KEY_EMPLOYEE = 'employee';

    protected $table = 'form_access_forms';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'key',
        'name',
    ];

    public function tabs(): HasMany
    {
        return $this->hasMany(FormAccessTab::class, 'form_id')->orderBy('sort')->orderBy('label');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(FormAccessField::class, 'form_id')->orderBy('sort')->orderBy('label');
    }
}
