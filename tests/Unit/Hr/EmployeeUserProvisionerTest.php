<?php

namespace Tests\Unit\Hr;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Modules\Core\Models\Person;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\Employee\EmployeeUserProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeUserProvisionerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_username_as_lastname_dot_firstname(): void
    {
        $provisioner = app(EmployeeUserProvisioner::class);

        $this->assertSame('doe.john', $provisioner->buildUsername('John', 'Doe'));
    }

    public function test_it_appends_random_suffix_when_username_exists(): void
    {
        Company::query()->create([
            'legalName' => 'Test Co',
            'alias' => 'Test',
            'socialSecurityNumber' => '123',
            'taxIdentificationNumber' => '',
            'logoPath' => '',
            'phoneNumber1' => '555',
            'phoneNumber2' => '',
            'email' => 'info@example.com',
            'street' => 'Main',
            'localityId' => null,
        ]);

        User::query()->create([
            'name' => 'Existing User',
            'email' => 'doe.john@example.com',
            'password' => bcrypt('secret'),
        ]);

        $provisioner = app(EmployeeUserProvisioner::class);
        $credentials = $provisioner->generateUniqueLoginCredentials('John', 'Doe');

        $this->assertSame('doe.john', $provisioner->buildUsername('John', 'Doe'));
        $this->assertNotSame('doe.john@example.com', $credentials['email']);
        $this->assertMatchesRegularExpression('/^doe\.john\d{2}@example\.com$/', $credentials['email']);
    }

    public function test_it_creates_employee_user_with_employee_role(): void
    {
        Role::query()->create([
            'name' => 'employee',
            'guard_name' => 'web',
        ]);

        Company::query()->create([
            'legalName' => 'Test Co',
            'alias' => 'Test',
            'socialSecurityNumber' => '123',
            'taxIdentificationNumber' => '',
            'logoPath' => '',
            'phoneNumber1' => '555',
            'phoneNumber2' => '',
            'email' => 'info@example.com',
            'street' => 'Main',
            'localityId' => null,
        ]);

        $person = Person::query()->create([
            'firstName' => 'Jane',
            'lastName' => 'Smith',
            'birthdate' => '1990-01-01',
            'address1' => '123 Main',
            'localityId' => null,
            'genderId' => null,
            'socialSecurityNumber' => 'SSN123',
        ]);

        $employee = Employee::query()->create([
            'person_id' => $person->id,
            'code' => 'EMP001',
            'paymentMethodId' => null,
        ]);

        $user = app(EmployeeUserProvisioner::class)->provisionForEmployee($employee);

        $this->assertNotNull($user);
        $this->assertSame('smith.jane@example.com', $user->email);
        $this->assertTrue($user->hasRole('employee'));
        $this->assertSame($user->id, $employee->fresh()->user_id);
    }
}
