<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CandidateStage extends Model
{
    protected $fillable = [
        'name',
        'color',
        'sort_order',
        'is_default',
        'is_hired',
        'is_rejected',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_default' => 'boolean',
        'is_hired' => 'boolean',
        'is_rejected' => 'boolean',
    ];

    public function applications(): HasMany
    {
        return $this->hasMany(VacancyApplication::class, 'candidate_stage_id')
            ->orderBy('sort_order')
            ->orderBy('created_at');
    }

    public static function defaultStage(): ?self
    {
        return static::query()->where('is_default', true)->orderBy('sort_order')->first()
            ?? static::query()->orderBy('sort_order')->first();
    }

    public static function hiredStage(): ?self
    {
        return static::query()->where('is_hired', true)->orderBy('sort_order')->first();
    }

    public function present(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'color' => $this->color,
            'sort_order' => $this->sort_order,
            'is_default' => $this->is_default,
            'is_hired' => $this->is_hired,
            'is_rejected' => $this->is_rejected,
        ];
    }
}