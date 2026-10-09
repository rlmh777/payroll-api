<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\District;
use App\Models\Gender;
use App\Models\Locality;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\EmployeePersonSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserEmployeeLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_links_a_user_to_an_employee_that_already_has_a_provisioned_account(): void
    {
        $lookups = $this->employeeLookups();
        $employee = EmployeePersonSync::create([
            'firstName' => 'Lloyd',
            'lastName' => 'Edwards',
            'email' => 'lloyd@example.com',
            'phone' => '615-0003',
            'birthdate' => '1990-01-01',
            'address1' => '1 Main Street',
            'localityId' => $lookups['locality']->id,
            'genderId' => $lookups['gender']->id,
            'socialSecurityNumber' => '10000333',
            'paymentMethodId' => $lookups['payment']->id,
            'code' => '100003',
        ]);

        $this->assertNotNull($employee->fresh()->user_id);
        $provisionedUserId = $employee->fresh()->user_id;

        $loginUser = User::query()->create([
            'name' => 'Support',
            'username' => 'support',
            'email' => 'support@example.com',
            'password' => bcrypt('secret'),
        ]);

        Sanctum::actingAs($this->usersAdmin());

        $this->putJson("/api/users/{$loginUser->id}/employee", [
            'employee_id' => $employee->id,
        ])
            ->assertOk()
            ->assertJsonPath('user.employee.id', $employee->id);

        $this->assertSame($loginUser->id, $employee->fresh()->user_id);
        $this->assertNull(Employee::query()->where('user_id', $provisionedUserId)->first());
    }

    public function test_it_unlinks_a_user_from_an_employee(): void
    {
        $lookups = $this->employeeLookups();
        $loginUser = User::query()->create([
            'name' => 'Support',
            'username' => 'support',
            'email' => 'support@example.com',
            'password' => bcrypt('secret'),
        ]);
        $employee = EmployeePersonSync::create([
            'firstName' => 'Lloyd',
            'lastName' => 'Edwards',
            'email' => 'lloyd@example.com',
            'phone' => '615-0003',
            'birthdate' => '1990-01-01',
            'address1' => '1 Main Street',
            'localityId' => $lookups['locality']->id,
            'genderId' => $lookups['gender']->id,
            'socialSecurityNumber' => '10000333',
            'paymentMethodId' => $lookups['payment']->id,
            'code' => '100003',
            'user_id' => $loginUser->id,
        ]);

        Sanctum::actingAs($this->usersAdmin());

        $this->putJson("/api/users/{$loginUser->id}/employee", [
            'employee_id' => null,
        ])->assertOk();

        $this->assertNull($employee->fresh()->user_id);
    }

    /**
     * @return array{locality: Locality, gender: Gender, payment: PaymentMethod}
     */
    private function employeeLookups(): array
    {
        $country = Country::query()->create([
            'name' => 'Belize',
            'code1' => 'BZ',
            'code2' => 'BLZ',
            'nationalityName' => 'Belizean',
        ]);
        $district = District::query()->create([
            'name' => 'Cayo',
            'countryId' => $country->id,
        ]);
        $locality = Locality::query()->create([
            'name' => 'San Ignacio',
            'districtId' => $district->id,
        ]);
        $gender = Gender::query()->create(['name' => 'Female']);
        $payment = PaymentMethod::query()->create(['name' => 'Bank transfer']);

        return compact('locality', 'gender', 'payment');
    }

    private function usersAdmin(): User
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => 'manager-users',
            'guard_name' => 'web',
        ]);

        $admin = User::query()->create([
            'name' => 'Admin',
            'username' => 'admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('secret'),
        ]);
        $admin->givePermissionTo($permission);

        return $admin;
    }
}
