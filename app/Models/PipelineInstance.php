<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PipelineInstance extends Model
{
    use HasUuids;

    protected $table = 'pipeline_instances';

    protected $fillable = [
        'pipeline_template_id',
        'current_step_id',
        'subject_type',
        'subject_id',
        'status',
        'started_by_user_id',
        'completed_at',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(PipelineTemplate::class, 'pipeline_template_id');
    }

    public function currentStep(): BelongsTo
    {
        return $this->belongsTo(PipelineTemplateStep::class, 'current_step_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(PipelineAction::class, 'pipeline_instance_id')
            ->orderBy('created_at');
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }
}
