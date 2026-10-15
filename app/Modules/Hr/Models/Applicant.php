<?php

namespace App\Modules\Hr\Models;

use App\Models\Gender;
use App\Models\Locality;
use App\Modules\Core\Models\Person;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Applicant extends Model
{
    use HasUuids;

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'first_name',
        'middle_name',
        'last_name',
        'email',
        'phone',
        'address1',
        'address2',
        'locality_id',
        'birthdate',
        'gender_id',
        'social_security_number',
        'notes',
        'employee_id',
    ];

    protected $casts = [
        'birthdate' => 'date:Y-m-d',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function locality(): BelongsTo
    {
        return $this->belongsTo(Locality::class, 'locality_id');
    }

    public function gender(): BelongsTo
    {
        return $this->belongsTo(Gender::class, 'gender_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(VacancyApplication::class, 'applicant_id');
    }

    public static function normalizeEmail(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));

        return $email !== '' ? $email : null;
    }

    public function displayName(): string
    {
        return trim(implode(' ', array_filter([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
        ])));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function upsertFromApplication(array $attributes): self
    {
        $email = self::normalizeEmail($attributes['email'] ?? null);
        if (! $email) {
            throw new \InvalidArgumentException('Applicant email is required.');
        }

        $payload = [
            'first_name' => trim((string) ($attributes['first_name'] ?? '')),
            'middle_name' => self::nullableString($attributes['middle_name'] ?? null),
            'last_name' => trim((string) ($attributes['last_name'] ?? '')),
            'email' => $email,
            'phone' => self::nullableString($attributes['phone'] ?? null),
            'address1' => self::nullableString($attributes['address1'] ?? null),
            'address2' => self::nullableString($attributes['address2'] ?? null),
            'locality_id' => $attributes['locality_id'] ?? null,
            'birthdate' => $attributes['birthdate'] ?? null,
            'gender_id' => $attributes['gender_id'] ?? null,
            'social_security_number' => self::nullableString($attributes['social_security_number'] ?? null),
            'notes' => self::nullableString($attributes['notes'] ?? null),
        ];

        $applicant = static::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($applicant) {
            $applicant->fill(array_filter(
                $payload,
                fn ($value, $key) => $key !== 'email' && $value !== null && $value !== '',
                ARRAY_FILTER_USE_BOTH,
            ));
            $applicant->save();

            return $applicant->fresh();
        }

        return static::query()->create($payload);
    }

    public function matchingEmployee(): ?Employee
    {
        if ($this->employee_id) {
            return $this->employee()->with('person')->first();
        }

        $query = Employee::query()->with('person')->whereHas('person', function ($person) {
            $person->where(function ($builder) {
                $email = self::normalizeEmail($this->email);
                if ($email) {
                    $builder->orWhereRaw('LOWER(email) = ?', [$email]);
                }

                $ssn = trim((string) $this->social_security_number);
                if ($ssn !== '') {
                    $builder->orWhere('socialSecurityNumber', $ssn);
                }
            });
        });

        if (! self::normalizeEmail($this->email) && trim((string) $this->social_security_number) === '') {
            return null;
        }

        return $query->first();
    }

    /**
     * Person attributes that can be copied onto an employee.
     *
     * @return array<string, mixed>
     */
    public function personAttributes(): array
    {
        return array_filter([
            'firstName' => $this->first_name,
            'middleName' => $this->middle_name,
            'lastName' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address1' => $this->address1,
            'address2' => $this->address2,
            'localityId' => $this->locality_id,
            'birthdate' => $this->birthdate?->format('Y-m-d'),
            'genderId' => $this->gender_id,
            'socialSecurityNumber' => $this->social_security_number,
            'notes' => $this->notes,
        ], fn ($value) => $value !== null && $value !== '');
    }

    public function present(): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'name' => $this->displayName(),
            'email' => $this->email,
            'phone' => $this->phone,
            'address1' => $this->address1,
            'address2' => $this->address2,
            'locality_id' => $this->locality_id,
            'locality' => $this->locality ? [
                'id' => $this->locality->id,
                'name' => $this->locality->name,
            ] : null,
            'birthdate' => $this->birthdate?->format('Y-m-d'),
            'gender_id' => $this->gender_id,
            'gender' => $this->gender ? [
                'id' => $this->gender->id,
                'name' => $this->gender->name,
            ] : null,
            'social_security_number' => $this->social_security_number,
            'notes' => $this->notes,
            'employee_id' => $this->employee_id,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
