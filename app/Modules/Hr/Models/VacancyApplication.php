<?php

namespace App\Modules\Hr\Models;

use App\Support\ConfiguredStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

class VacancyApplication extends Model
{
    use HasUuids;

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    public const STATUS_RECEIVED = 'received';

    public const STATUS_CONVERTED = 'converted';

    public const SOURCE_PUBLIC = 'public';

    public const SOURCE_INTERNAL = 'internal';

    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'vacancy_id',
        'applicant_id',
        'candidate_stage_id',
        'cover_letter',
        'resume_path',
        'resume_name',
        'source',
        'status',
        'sort_order',
        'converted_at',
    ];

    protected $casts = [
        'candidate_stage_id' => 'integer',
        'sort_order' => 'integer',
        'converted_at' => 'datetime',
    ];

    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(Vacancy::class, 'vacancy_id');
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class, 'applicant_id');
    }

    public function candidateStage(): BelongsTo
    {
        return $this->belongsTo(CandidateStage::class, 'candidate_stage_id');
    }

    public function scopeApplyFilters(Builder $query, Request $request): Builder
    {
        if ($request->filled('search')) {
            $search = (string) $request->input('search');
            $query->where(function (Builder $builder) use ($search) {
                $builder->whereHas('applicant', function (Builder $applicant) use ($search) {
                    $applicant->where('first_name', 'ilike', "%{$search}%")
                        ->orWhere('last_name', 'ilike', "%{$search}%")
                        ->orWhere('email', 'ilike', "%{$search}%")
                        ->orWhere('phone', 'ilike', "%{$search}%");
                })->orWhereHas('vacancy', function (Builder $vacancy) use ($search) {
                    $vacancy->where('title', 'ilike', "%{$search}%");
                });
            });
        }

        if ($request->filled('vacancy_id')) {
            $query->where('vacancy_id', (string) $request->input('vacancy_id'));
        }

        if ($request->filled('source')) {
            $query->where('source', (string) $request->input('source'));
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        if ($request->filled('candidate_stage_id')) {
            $query->where('candidate_stage_id', (int) $request->input('candidate_stage_id'));
        }

        if ($request->filled('job_title_id')) {
            $query->whereHas('vacancy', fn (Builder $vacancy) => $vacancy->where('job_title_id', (int) $request->input('job_title_id')));
        }

        if ($request->filled('department_id')) {
            $query->whereHas('vacancy', fn (Builder $vacancy) => $vacancy->where('department_id', (int) $request->input('department_id')));
        }

        if ($request->filled('worksite_id')) {
            $query->whereHas('vacancy', fn (Builder $vacancy) => $vacancy->where('worksite_id', (int) $request->input('worksite_id')));
        }

        if ($request->filled('hiring_manager_id')) {
            $query->whereHas('vacancy', fn (Builder $vacancy) => $vacancy->where('hiring_manager_id', (string) $request->input('hiring_manager_id')));
        }

        if ($request->boolean('advertise_internal')) {
            $query->whereHas('vacancy', fn (Builder $vacancy) => $vacancy->where('advertise_internal', true));
        }

        if ($request->boolean('advertise_public')) {
            $query->whereHas('vacancy', fn (Builder $vacancy) => $vacancy->where('advertise_public', true));
        }

        return $query;
    }

    public static function defaultRelations(): array
    {
        return [
            'applicant.locality',
            'applicant.gender',
            'applicant.employee.person',
            'candidateStage',
            'vacancy.jobTitle',
            'vacancy.worksite',
            'vacancy.department',
            'vacancy.hiringManager.person',
            'vacancy.stage',
        ];
    }

    public function present(): array
    {
        $applicant = $this->applicant;
        $matching = $applicant?->matchingEmployee();
        $person = $matching?->person;

        return [
            'id' => $this->id,
            'vacancy_id' => $this->vacancy_id,
            'vacancy_title' => $this->vacancy?->title,
            'vacancy' => $this->vacancy ? [
                'id' => $this->vacancy->id,
                'title' => $this->vacancy->title,
                'job_title' => $this->vacancy->jobTitle?->name,
                'worksite' => $this->vacancy->worksite?->name,
                'department' => $this->vacancy->department?->name,
            ] : null,
            'applicant_id' => $this->applicant_id,
            'applicant' => $applicant?->present(),
            'candidate_stage_id' => $this->candidate_stage_id,
            'candidate_stage' => $this->candidateStage?->present(),
            'cover_letter' => $this->cover_letter,
            'resume_path' => $this->resume_path,
            'resume_name' => $this->resume_name,
            'resume_url' => app(ConfiguredStorage::class)->urlOrNull($this->resume_path),
            'source' => $this->source,
            'status' => $this->status,
            'sort_order' => $this->sort_order,
            'converted_at' => $this->converted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'matching_employee' => $matching ? [
                'id' => $matching->id,
                'code' => $matching->code,
                'firstName' => $person?->firstName,
                'lastName' => $person?->lastName,
                'email' => $person?->email,
            ] : null,
        ];
    }
}
