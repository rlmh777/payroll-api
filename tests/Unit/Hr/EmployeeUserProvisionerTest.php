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

    public function test_it_builds_username_as_firstname_dot_lastname(): void
    {
        $provisioner = app(EmployeeUserProvisioner::class);

        $this->assertSame('john.doe', $provisioner->buildUsername('John', 'Doe'));
    }

    public function test_it_builds_username_with_middle_initial_when_provided(): void
    {
        $provisioner = app(EmployeeUserProvisioner::class);

        $this->assertSame('john.m.doe', $provisioner->buildUsername('John', 'Doe', 'Michael'));
    }

    public function test_it_prefers_base_then_middle_initial_then_numeric_suffix(): void
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
            'email' => 'john.doe@example.com',
            'password' => bcrypt('secret'),
        ]);

        $provisioner = app(EmployeeUserProvisioner::class);

        $withMiddle = $provisioner->generateUniqueLoginCredentials('John', 'Doe', 'Michael');
        $this->assertSame('john.m.doe', $withMiddle['username']);
        $this->assertSame('john.m.doe@example.com', $withMiddle['email']);

        User::query()->create([
            'name' => 'Middle Taken',
            'email' => 'john.m.doe@example.com',
            'password' => bcrypt('secret'),
        ]);

        $withSuffix = $provisioner->generateUniqueLoginCredentials('John', 'Doe', 'Michael');
        $this->assertSame('john.doe2', $withSuffix['username']);
        $this->assertSame('john.doe2@example.com', $withSuffix['email']);
    }

    public function test_it_skips_middle_initial_and_uses_numeric_suffix_when_no_middle_name(): void
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
            'email' => 'john.doe@example.com',
            'password' => bcrypt('secret'),
        ]);

        $provisioner = app(EmployeeUserProvisioner::class);
        $credentials = $provisioner->generateUniqueLoginCredentials('John', 'Doe');

        $this->assertSame('john.doe2', $credentials['username']);
        $this->assertSame('john.doe2@example.com', $credentials['email']);
    }

    public function test_it_increments_numeric_suffix_when_needed(): void
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

        foreach (['john.doe@example.com', 'john.doe2@example.com'] as $email) {
            User::query()->create([
                'name' => 'Existing User',
                'email' => $email,
                'password' => bcrypt('secret'),
            ]);
        }

        $provisioner = app(EmployeeUserProvisioner::class);
        $credentials = $provisioner->generateUniqueLoginCredentials('John', 'Doe');

        $this->assertSame('john.doe3', $credentials['username']);
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
            'middleName' => 'Ann',
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
        $this->assertSame('jane.smith@example.com', $user->email);
        $this->assertTrue($user->hasRole('employee'));
        $this->assertSame($user->id, $employee->fresh()->user_id);
    }
}
