<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\District;
use App\Models\Gender;
use App\Models\Locality;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Modules\Hr\Services\EmployeePersonSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeSelfProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_linked_user_can_read_their_own_profile_without_view_employees(): void
    {
        $user = $this->linkedEmployeeUser();
        Sanctum::actingAs($user);

        $this->getJson('/api/employees/me')
            ->assertOk()
            ->assertJsonPath('firstName', 'Lloyd')
            ->assertJsonPath('lastName', 'Edwards');

        $this->getJson('/api/genders')->assertOk();
    }

    public function test_linked_user_cannot_list_or_read_other_employees(): void
    {
        $user = $this->linkedEmployeeUser();
        $other = $this->createEmployee('other@example.com', 'Ada', 'Lovelace');
        Sanctum::actingAs($user);

        $this->getJson('/api/employees')->assertForbidden();
        $this->getJson("/api/employees/{$other->id}")->assertForbidden();
        $this->putJson("/api/employees/{$user->employee->id}", [
            'firstName' => 'Changed',
        ])->assertForbidden();
    }

    public function test_unlinked_user_cannot_load_a_profile(): void
    {
        $user = User::query()->create([
            'name' => 'No Employee',
            'username' => 'noemployee',
            'email' => 'noemployee@example.com',
            'password' => bcrypt('secret'),
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/employees/me')
            ->assertNotFound();
    }

    private function linkedEmployeeUser(): User
    {
        $employee = $this->createEmployee('lloyd@example.com', 'Lloyd', 'Edwards');

        return $employee->user()->firstOrFail();
    }

    private function createEmployee(string $email, string $first, string $last)
    {
        $lookups = $this->employeeLookups();

        return EmployeePersonSync::create([
            'firstName' => $first,
            'lastName' => $last,
            'email' => $email,
            'phone' => '615-0003',
            'birthdate' => '1990-01-01',
            'address1' => '1 Main Street',
            'localityId' => $lookups['locality']->id,
            'genderId' => $lookups['gender']->id,
            'socialSecurityNumber' => substr(str_replace(['@', '.'], '', $email).'00000000', 0, 8),
            'paymentMethodId' => $lookups['payment']->id,
            'code' => substr(md5($email), 0, 6),
        ]);
    }

    /**
     * @return array{locality: Locality, gender: Gender, payment: PaymentMethod}
     */
    private function employeeLookups(): array
    {
        $country = Country::query()->firstOrCreate(
            ['code1' => 'BZ'],
            ['name' => 'Belize', 'code2' => 'BLZ', 'nationalityName' => 'Belizean'],
        );
        $district = District::query()->firstOrCreate(
            ['name' => 'Cayo', 'countryId' => $country->id],
        );
        $locality = Locality::query()->firstOrCreate(
            ['name' => 'San Ignacio', 'districtId' => $district->id],
        );
        $gender = Gender::query()->firstOrCreate(['name' => 'Female']);
        $payment = PaymentMethod::query()->firstOrCreate(['name' => 'Bank transfer']);

        return compact('locality', 'gender', 'payment');
    }
}
