<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormAccessTab extends Model
{
    use HasUuids;

    protected $table = 'form_access_tabs';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'form_id',
        'key',
        'label',
        'group',
        'sort',
        'is_system',
        'is_active',
    ];

    protected $casts = [
        'sort' => 'integer',
        'is_system' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function form(): BelongsTo
    {
        return $this->belongsTo(FormAccessForm::class, 'form_id');
    }
}
