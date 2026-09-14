<?php

namespace App\Modules\Hr\Services\Employee;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Modules\Hr\Models\Employee;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class EmployeeUserProvisioner
{
    public function buildUsername(string $firstName, string $lastName, ?string $middleName = null): string
    {
        $first = $this->slugNamePart($firstName);
        $last = $this->slugNamePart($lastName);

        if ($first === '' || $last === '') {
            throw new RuntimeException('First and last name are required to generate a username.');
        }

        $middleInitial = $this->middleInitial($middleName);
        if ($middleInitial !== null) {
            return "{$first}.{$middleInitial}.{$last}";
        }

        return "{$first}.{$last}";
    }

    /**
     * @return array{username: string, email: string}
     */
    public function generateUniqueLoginCredentials(
        string $firstName,
        string $lastName,
        ?string $middleName = null,
    ): array {
        $domain = $this->resolveEmailDomain();
        $candidates = $this->usernameCandidates($firstName, $lastName, $middleName);

        foreach ($candidates as $username) {
            $email = "{$username}@{$domain}";

            if (!$this->loginEmailExists($email)) {
                return compact('username', 'email');
            }
        }

        throw new RuntimeException('Unable to generate a unique employee login username.');
    }

    /**
     * Preferred order:
     * 1. firstname.lastname
     * 2. firstname.m.lastname (middle initial, when available)
     * 3. firstname.lastname2, firstname.lastname3, ...
     *
     * @return list<string>
     */
    public function usernameCandidates(
        string $firstName,
        string $lastName,
        ?string $middleName = null,
    ): array {
        $first = $this->slugNamePart($firstName);
        $last = $this->slugNamePart($lastName);

        if ($first === '' || $last === '') {
            throw new RuntimeException('First and last name are required to generate a username.');
        }

        $candidates = ["{$first}.{$last}"];

        $middleInitial = $this->middleInitial($middleName);
        if ($middleInitial !== null) {
            $candidates[] = "{$first}.{$middleInitial}.{$last}";
        }

        for ($suffix = 2; $suffix <= 100; $suffix++) {
            $candidates[] = "{$first}.{$last}{$suffix}";
        }

        return $candidates;
    }

    public function provisionForEmployee(Employee $employee): ?User
    {
        if ($employee->user_id) {
            return $employee->user;
        }

        $employee->loadMissing('person');
        $person = $employee->person;

        if (!$person) {
            return null;
        }

        ['username' => $username, 'email' => $email] = $this->generateUniqueLoginCredentials(
            (string) $person->firstName,
            (string) $person->lastName,
            $person->middleName !== null ? (string) $person->middleName : null,
        );

        $user = User::query()->create([
            'name' => trim("{$person->firstName} {$person->lastName}"),
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
        $configuredDomain = trim((string) config('payroll.employee_login_domain'));
        if ($configuredDomain !== '') {
            return $configuredDomain;
        }

        $companyEmail = Company::query()->value('email');
        if (is_string($companyEmail) && str_contains($companyEmail, '@')) {
            return Str::after($companyEmail, '@');
        }

        return 'payroll.local';
    }

    private function middleInitial(?string $middleName): ?string
    {
        if ($middleName === null) {
            return null;
        }

        $slug = $this->slugNamePart($middleName);
        if ($slug === '') {
            return null;
        }

        return $slug[0];
    }

    private function slugNamePart(string $value): string
    {
        $normalized = Str::slug(strtolower(trim($value)), '');

        return preg_replace('/[^a-z0-9]/', '', $normalized) ?? '';
    }

    private function loginEmailExists(string $email): bool
    {
        return User::query()->where('email', $email)->exists();
    }
}
