<?php

namespace Tests\Unit\Hr;

use App\Models\User;
use App\Modules\Hr\Services\Employee\EmployeeUserProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeUserProvisionerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['payroll.employee_login_domain' => 'example.com']);
    }

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

    public function test_it_generates_unique_login_credentials_for_an_employee_name(): void
    {
        $credentials = app(EmployeeUserProvisioner::class)
            ->generateUniqueLoginCredentials('Jane', 'Smith', 'Ann');

        $this->assertSame('jane.smith', $credentials['username']);
        $this->assertSame('jane.smith@example.com', $credentials['email']);
    }
}
