<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormResponse extends Model
{
    use HasUuids;

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'form_template_id',
        'form_assignment_id',
        'vacancy_id',
        'vacancy_application_id',
        'panel_member_id',
        'user_id',
        'schema_snapshot',
        'answers',
        'comments',
        'total_score',
        'max_score',
        'submitted_at',
    ];

    protected $casts = [
        'schema_snapshot' => 'array',
        'answers' => 'array',
        'total_score' => 'float',
        'max_score' => 'float',
        'submitted_at' => 'datetime',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(FormTemplate::class, 'form_template_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(FormAssignment::class, 'form_assignment_id');
    }

    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(Vacancy::class, 'vacancy_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(VacancyApplication::class, 'vacancy_application_id');
    }

    public function panelMember(): BelongsTo
    {
        return $this->belongsTo(VacancyPanelMember::class, 'panel_member_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function present(bool $includeAnswers = true): array
    {
        $schema = is_array($this->schema_snapshot) ? $this->schema_snapshot : ['fields' => []];
        $member = $this->panelMember;
        $user = $this->user;

        $payload = [
            'id' => $this->id,
            'form_template_id' => $this->form_template_id,
            'form_assignment_id' => $this->form_assignment_id,
            'vacancy_id' => $this->vacancy_id,
            'vacancy_application_id' => $this->vacancy_application_id,
            'panel_member_id' => $this->panel_member_id,
            'user_id' => $this->user_id,
            'scorer' => [
                'name' => $member?->name ?: $user?->name,
                'email' => $member?->email ?: $user?->email,
            ],
            'comments' => $this->comments,
            'total_score' => $this->total_score,
            'max_score' => $this->max_score,
            'percent' => $this->max_score
                ? round(((float) $this->total_score / (float) $this->max_score) * 100, 1)
                : null,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'is_submitted' => $this->submitted_at !== null,
        ];

        if ($includeAnswers) {
            $payload['schema'] = $schema;
            $payload['answers'] = $this->answers ?? [];
        }

        return $payload;
    }
}
