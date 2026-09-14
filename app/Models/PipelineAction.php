<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineAction extends Model
{
    use HasUuids;

    protected $table = 'pipeline_actions';

    protected $fillable = [
        'pipeline_instance_id',
        'from_step_id',
        'to_step_id',
        'actor_user_id',
        'action',
        'note',
        'resulting_status',
    ];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(PipelineInstance::class, 'pipeline_instance_id');
    }

    public function fromStep(): BelongsTo
    {
        return $this->belongsTo(PipelineTemplateStep::class, 'from_step_id');
    }

    public function toStep(): BelongsTo
    {
        return $this->belongsTo(PipelineTemplateStep::class, 'to_step_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
