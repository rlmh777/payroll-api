<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineTemplateStep extends Model
{
    use HasUuids;

    protected $table = 'pipeline_template_steps';

    protected $fillable = [
        'pipeline_template_id',
        'key',
        'label',
        'sort_order',
        'assignee_type',
        'assignee_role',
        'domain_status',
        'actions',
        'ui_config',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'actions' => 'array',
        'ui_config' => 'array',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(PipelineTemplate::class, 'pipeline_template_id');
    }
}
