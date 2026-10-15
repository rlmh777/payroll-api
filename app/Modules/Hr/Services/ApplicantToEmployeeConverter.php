<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Applicant;
use App\Modules\Hr\Models\CandidateStage;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\VacancyApplication;
use App\Modules\Hr\Services\Employee\EmployeeCodeGenerator;
use App\Support\Access;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplicantToEmployeeConverter
{
    public function __construct(
        private readonly EmployeeCodeGenerator $codeGenerator,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{action: string, employee: Employee, application: VacancyApplication}
     */
    public function convert(VacancyApplication $application, array $attributes, ?User $actor): array
    {
        if (! Access::can($actor, 'employees-crud')) {
            abort(403, 'Forbidden. Missing required permission.');
        }

        $application->loadMissing(VacancyApplication::defaultRelations());
        $applicant = $application->applicant;
        if (! $applicant) {
            throw ValidationException::withMessages([
                'applicant' => 'This application has no applicant record.',
            ]);
        }

        return DB::transaction(function () use ($application, $applicant, $attributes) {
            $existing = $applicant->matchingEmployee();
            $employee = $existing
                ? EmployeePersonSync::update($existing, $this->mergePersonAttributes($applicant, $attributes))
                : $this->createEmployee($applicant, $attributes);

            $applicant->update(['employee_id' => $employee->id]);
            $payload = [
                'status' => VacancyApplication::STATUS_CONVERTED,
                'converted_at' => now(),
            ];
            $hired = CandidateStage::hiredStage();
            if ($hired) {
                $payload['candidate_stage_id'] = $hired->id;
                $payload['sort_order'] = (int) VacancyApplication::query()
                    ->where('candidate_stage_id', $hired->id)
                    ->max('sort_order') + 1;
            }
            $application->update($payload);

            return [
                'action' => $existing ? 'updated' : 'created',
                'employee' => $employee->fresh(EmployeePersonSync::defaultRelations()),
                'application' => $application->fresh(VacancyApplication::defaultRelations()),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createEmployee(Applicant $applicant, array $attributes): Employee
    {
        $personData = $this->mergePersonAttributes($applicant, $attributes);
        $missing = [];
        foreach (['firstName', 'lastName', 'birthdate', 'address1', 'localityId', 'genderId', 'socialSecurityNumber'] as $field) {
            if (! filled($personData[$field] ?? null)) {
                $missing[$field] = ["The {$field} field is required to create an employee."];
            }
        }
        if (! filled($attributes['paymentMethodId'] ?? null)) {
            $missing['paymentMethodId'] = ['A payment method is required to create an employee.'];
        }
        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }

        $personData['paymentMethodId'] = $attributes['paymentMethodId'];
        $personData['code'] = $this->codeGenerator->generate(
            (string) $personData['firstName'],
            (string) $personData['lastName'],
        );

        return EmployeePersonSync::create($personData);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function mergePersonAttributes(Applicant $applicant, array $attributes): array
    {
        $fromRequest = array_filter([
            'firstName' => $attributes['first_name'] ?? $attributes['firstName'] ?? null,
            'middleName' => $attributes['middle_name'] ?? $attributes['middleName'] ?? null,
            'lastName' => $attributes['last_name'] ?? $attributes['lastName'] ?? null,
            'email' => $attributes['email'] ?? null,
            'phone' => $attributes['phone'] ?? null,
            'address1' => $attributes['address1'] ?? null,
            'address2' => $attributes['address2'] ?? null,
            'localityId' => $attributes['locality_id'] ?? $attributes['localityId'] ?? null,
            'birthdate' => $attributes['birthdate'] ?? null,
            'genderId' => $attributes['gender_id'] ?? $attributes['genderId'] ?? null,
            'socialSecurityNumber' => $attributes['social_security_number'] ?? $attributes['socialSecurityNumber'] ?? null,
            'notes' => $attributes['notes'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        return array_merge($applicant->personAttributes(), $fromRequest);
    }
}
