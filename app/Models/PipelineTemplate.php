<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PipelineTemplate extends Model
{
    use HasUuids;

    protected $table = 'pipeline_templates';

    protected $fillable = [
        'key',
        'name',
        'subject_type',
        'description',
        'completion_status',
        'rejection_status',
        'cancellation_status',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function steps(): HasMany
    {
        return $this->hasMany(PipelineTemplateStep::class, 'pipeline_template_id')
            ->orderBy('sort_order')
            ->orderBy('created_at');
    }

    public function instances(): HasMany
    {
        return $this->hasMany(PipelineInstance::class, 'pipeline_template_id');
    }
}
