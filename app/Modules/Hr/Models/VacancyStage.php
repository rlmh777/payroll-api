<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VacancyStage extends Model
{
    protected $fillable = [
        'name',
        'color',
        'sort_order',
        'is_default',
        'lists_public',
        'is_closed',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_default' => 'boolean',
        'lists_public' => 'boolean',
        'is_closed' => 'boolean',
    ];

    public function vacancies(): HasMany
    {
        return $this->hasMany(Vacancy::class, 'vacancy_stage_id')->orderBy('sort_order')->orderBy('created_at');
    }

    public static function defaultStage(): ?self
    {
        return static::query()->where('is_default', true)->orderBy('sort_order')->first()
            ?? static::query()->orderBy('sort_order')->first();
    }

    public function present(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'color' => $this->color,
            'sort_order' => $this->sort_order,
            'is_default' => $this->is_default,
            'lists_public' => $this->lists_public,
            'is_closed' => $this->is_closed,
        ];
    }
}
