<?php

namespace App\Modules\Hr\Models;

use App\Models\Department;
use App\Models\JobTitle;
use App\Models\Worksite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;

class Vacancy extends Model
{
    use HasUuids;

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'title',
        'job_title_id',
        'worksite_id',
        'department_id',
        'hiring_manager_id',
        'positions',
        'require_resume',
        'description',
        'advertise_internal',
        'advertise_public',
        'vacancy_stage_id',
        'sort_order',
    ];

    protected $casts = [
        'job_title_id' => 'integer',
        'worksite_id' => 'integer',
        'department_id' => 'integer',
        'positions' => 'integer',
        'require_resume' => 'boolean',
        'advertise_internal' => 'boolean',
        'advertise_public' => 'boolean',
        'vacancy_stage_id' => 'integer',
        'sort_order' => 'integer',
    ];

    public function stage(): BelongsTo
    {
        return $this->belongsTo(VacancyStage::class, 'vacancy_stage_id');
    }

    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(JobTitle::class, 'job_title_id');
    }

    public function worksite(): BelongsTo
    {
        return $this->belongsTo(Worksite::class, 'worksite_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function hiringManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'hiring_manager_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(VacancyApplication::class, 'vacancy_id');
    }

    public function scopeApplyFilters(Builder $query, Request $request): Builder
    {
        if ($request->filled('search')) {
            $search = (string) $request->input('search');
            $query->where(function (Builder $builder) use ($search) {
                $builder->where('title', 'ilike', "%{$search}%")
                    ->orWhereHas('jobTitle', fn (Builder $jobTitle) => $jobTitle->where('name', 'ilike', "%{$search}%"));
            });
        }

        if ($request->filled('vacancy_stage_id')) {
            $query->where('vacancy_stage_id', (int) $request->input('vacancy_stage_id'));
        }

        if ($request->filled('job_title_id')) {
            $query->where('job_title_id', (int) $request->input('job_title_id'));
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', (int) $request->input('department_id'));
        }

        if ($request->filled('worksite_id')) {
            $query->where('worksite_id', (int) $request->input('worksite_id'));
        }

        if ($request->filled('hiring_manager_id')) {
            $query->where('hiring_manager_id', (string) $request->input('hiring_manager_id'));
        }

        if ($request->boolean('advertise_internal')) {
            $query->where('advertise_internal', true);
        }

        if ($request->boolean('advertise_public')) {
            $query->where('advertise_public', true);
        }

        return $query;
    }

    public function isListedPublicly(): bool
    {
        $stage = $this->stage;

        return $this->advertise_public
            && $stage
            && $stage->lists_public
            && ! $stage->is_closed;
    }

    public static function defaultRelations(): array
    {
        return [
            'stage',
            'jobTitle',
            'worksite',
            'department',
            'hiringManager.person',
        ];
    }

    public function present(bool $public = false): array
    {
        $jobTitle = $this->jobTitle;
        $worksite = $this->worksite;
        $department = $this->department;
        $manager = $this->hiringManager;
        $person = $manager?->person;
        $stage = $this->stage;

        $payload = [
            'id' => $this->id,
            'title' => $this->title,
            'job_title_id' => $this->job_title_id,
            'job_title' => $jobTitle ? [
                'id' => $jobTitle->id,
                'name' => $jobTitle->name,
                'notes' => $jobTitle->notes,
                'jobDescriptionUrl' => $jobTitle->jobDescriptionUrl,
            ] : null,
            'worksite_id' => $this->worksite_id,
            'worksite' => $worksite ? [
                'id' => $worksite->id,
                'name' => $worksite->name,
            ] : null,
            'department_id' => $this->department_id,
            'department' => $department ? [
                'id' => $department->id,
                'name' => $department->name,
            ] : null,
            'positions' => $this->positions,
            'require_resume' => $this->require_resume,
            'description' => $this->description,
            'advertise_internal' => $this->advertise_internal,
            'advertise_public' => $this->advertise_public,
            'vacancy_stage_id' => $this->vacancy_stage_id,
            'stage' => $stage?->present(),
            'sort_order' => $this->sort_order,
            'applications_count' => (int) ($this->applications_count ?? 0),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        if (! $public) {
            $payload['hiring_manager_id'] = $this->hiring_manager_id;
            $payload['hiring_manager'] = $manager ? [
                'id' => $manager->id,
                'code' => $manager->code,
                'firstName' => $person?->firstName,
                'lastName' => $person?->lastName,
            ] : null;
        }

        return $payload;
    }
}
