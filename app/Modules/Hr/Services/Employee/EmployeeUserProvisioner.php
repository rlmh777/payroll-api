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
    public function buildUsername(string $firstName, string $lastName): string
    {
        $last = $this->slugNamePart($lastName);
        $first = $this->slugNamePart($firstName);

        if ($last === '' || $first === '') {
            throw new RuntimeException('First and last name are required to generate a username.');
        }

        return "{$last}.{$first}";
    }

    /**
     * @return array{username: string, email: string}
     */
    public function generateUniqueLoginCredentials(string $firstName, string $lastName): array
    {
        $domain = $this->resolveEmailDomain();
        $baseUsername = $this->buildUsername($firstName, $lastName);
        $username = $baseUsername;
        $email = "{$username}@{$domain}";

        if (!$this->loginEmailExists($email)) {
            return compact('username', 'email');
        }

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $suffix = (string) random_int(10, 99);
            $username = "{$baseUsername}{$suffix}";
            $email = "{$username}@{$domain}";

            if (!$this->loginEmailExists($email)) {
                return compact('username', 'email');
            }
        }

        throw new RuntimeException('Unable to generate a unique employee login username.');
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
