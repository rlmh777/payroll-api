<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormAccessField extends Model
{
    use HasUuids;

    protected $table = 'form_access_fields';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    public const INPUT_TYPES = ['text', 'textarea', 'number', 'date', 'select', 'toggle'];

    protected $fillable = [
        'form_id',
        'tab_key',
        'key',
        'label',
        'sort',
        'input_type',
        'is_required',
        'options',
        'help_text',
        'is_system',
        'is_active',
    ];

    protected $casts = [
        'sort' => 'integer',
        'is_required' => 'boolean',
        'options' => 'array',
        'is_system' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function form(): BelongsTo
    {
        return $this->belongsTo(FormAccessForm::class, 'form_id');
    }
}
