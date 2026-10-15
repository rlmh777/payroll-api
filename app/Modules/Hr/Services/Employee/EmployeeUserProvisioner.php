<?php

namespace App\Modules\Hr\Services\Employee;

use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Modules\Hr\Models\Employee;
use App\Services\UsernameGenerator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class EmployeeUserProvisioner
{
    public function __construct(
        private readonly UsernameGenerator $usernames,
    ) {
    }

    public function buildUsername(string $firstName, string $lastName, ?string $middleName = null): string
    {
        return $this->usernames->buildUsername($firstName, $lastName, $middleName);
    }

    /**
     * @return array{username: string, email: string}
     */
    public function generateUniqueLoginCredentials(
        string $firstName,
        string $lastName,
        ?string $middleName = null,
    ): array {
        return $this->usernames->generateUniqueLoginCredentials($firstName, $lastName, $middleName);
    }

    /**
     * @return list<string>
     */
    public function usernameCandidates(
        string $firstName,
        string $lastName,
        ?string $middleName = null,
    ): array {
        return $this->usernames->usernameCandidates($firstName, $lastName, $middleName);
    }

    public function provisionForEmployee(Employee $employee): ?User
    {
        if ($employee->user_id) {
            return $employee->user;
        }

        $employee->loadMissing('person');
        $person = $employee->person;

        if (! $person) {
            return null;
        }

        ['username' => $username, 'email' => $email] = $this->generateUniqueLoginCredentials(
            (string) $person->firstName,
            (string) $person->lastName,
            $person->middleName !== null ? (string) $person->middleName : null,
        );

        $user = User::query()->create([
            'name' => trim("{$person->firstName} {$person->lastName}"),
            'username' => $username,
            'email' => $email,
            'password' => Hash::make((string) config('payroll.employee_default_password')),
        ]);

        $employeeRole = Role::query()->firstOrCreate([
            'name' => 'employee',
            'guard_name' => 'web',
        ]);

        $user->syncRoles([$employeeRole->name]);

        UserRole::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'role_id' => $employeeRole->id,
            ],
            [
                'id' => (string) Str::uuid(),
            ],
        );

        $employee->update(['user_id' => $user->id]);

        return $user;
    }

    public function resolveEmailDomain(): string
    {
        return $this->usernames->resolveEmailDomain();
    }
}
