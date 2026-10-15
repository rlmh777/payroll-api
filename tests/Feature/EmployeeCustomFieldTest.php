<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\District;
use App\Models\Gender;
use App\Models\Locality;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Core\Models\FormAccessField;
use App\Modules\Hr\Services\EmployeePersonSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeCustomFieldTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_can_create_and_list_custom_employee_fields(): void
    {
        Sanctum::actingAs($this->hrUser());

        $created = $this->postJson('/api/form-access/employee/fields', [
            'label' => 'Uniform size',
            'tab_key' => 'personal',
            'input_type' => 'select',
            'options' => [
                ['label' => 'Small', 'value' => 'S'],
                ['label' => 'Large', 'value' => 'L'],
            ],
            'is_required' => false,
            'is_active' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.key', 'uniformSize')
            ->assertJsonPath('data.is_system', false)
            ->json('data');

        $this->getJson('/api/form-access/employee')
            ->assertOk()
            ->assertJsonFragment(['key' => 'uniformSize', 'label' => 'Uniform size']);

        $this->getJson('/api/roles/employee-form-access/catalog')
            ->assertOk()
            ->assertJsonFragment(['key' => 'uniformSize', 'label' => 'Uniform size']);

        $this->putJson("/api/form-access/employee/fields/{$created['id']}", [
            'label' => 'Shirt size',
            'tab_key' => 'personal',
            'input_type' => 'select',
            'options' => [
                ['label' => 'Medium', 'value' => 'M'],
            ],
            'is_active' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.label', 'Shirt size');
    }

    public function test_built_in_fields_cannot_be_changed_or_deleted(): void
    {
        Sanctum::actingAs($this->hrUser());

        $system = FormAccessField::query()->where('key', 'firstName')->where('is_system', true)->firstOrFail();

        $this->putJson("/api/form-access/employee/fields/{$system->id}", [
            'label' => 'Given name',
            'tab_key' => 'personal',
            'input_type' => 'text',
        ])->assertStatus(422);

        $this->deleteJson("/api/form-access/employee/fields/{$system->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('form_access_fields', [
            'id' => $system->id,
            'key' => 'firstName',
        ]);
    }

    public function test_employee_custom_values_respect_form_access(): void
    {
        $field = $this->createCustomField('uniformSize', 'Uniform size');
        $lookups = $this->employeeLookups();

        $editor = $this->userWithPermissions([
            'view-employees',
            'employees-crud',
            'view-hr-settings',
            'view-roles',
            'roles-crud',
        ]);
        $editorRole = Role::query()->create(['name' => 'form-editor', 'guard_name' => 'web']);
        app(\App\Modules\Core\Services\FormAccessCatalog::class)->forget();
        $editorRole->update([
            'employee_form_access' => app(\App\Services\EmployeeFormAccessService::class)->fullAccess(),
        ]);
        $editor->assignRole($editorRole);
        Sanctum::actingAs($editor->fresh());

        $employee = EmployeePersonSync::create([
            'firstName' => 'Maya',
            'lastName' => 'Herrera',
            'email' => 'maya@example.com',
            'phone' => '615-0001',
            'birthdate' => '1991-02-02',
            'address1' => '2 Main Street',
            'localityId' => $lookups['locality']->id,
            'genderId' => $lookups['gender']->id,
            'socialSecurityNumber' => '12345678',
            'paymentMethodId' => $lookups['payment']->id,
            'code' => 'MH9102',
            'customAttributes' => [$field->key => 'L'],
        ]);

        $updated = $this->putJson("/api/employees/{$employee->id}", [
            'customAttributes' => [$field->key => 'S'],
        ])->assertOk();
        $this->assertSame(
            'S',
            data_get($updated->json(), 'data.customAttributes.uniformSize'),
            json_encode($updated->json()),
        );

        $viewerRole = Role::query()->create(['name' => 'custom-viewer', 'guard_name' => 'web']);
        $viewerRole->update([
            'employee_form_access' => [
                'tabs' => ['personal' => 'view'],
                'fields' => [$field->key => 'hidden', 'firstName' => 'view'],
            ],
        ]);
        $viewer = $this->userWithPermissions(['view-employees']);
        $viewer->assignRole($viewerRole);

        Sanctum::actingAs($viewer);
        $hidden = $this->getJson("/api/employees/{$employee->id}")->assertOk()->json();
        $this->assertArrayNotHasKey('uniformSize', $hidden['customAttributes'] ?? []);

        Sanctum::actingAs($editor);
        $this->putJson("/api/employees/{$employee->id}", [
            'firstName' => 'Maya',
            'customAttributes' => [],
        ])->assertOk();

        $employee->refresh();
        $this->assertSame('S', $employee->customAttributes['uniformSize'] ?? null);
    }

    private function createCustomField(string $key, string $label): FormAccessField
    {
        Sanctum::actingAs($this->hrUser());
        $created = $this->postJson('/api/form-access/employee/fields', [
            'label' => $label,
            'key' => $key,
            'tab_key' => 'personal',
            'input_type' => 'text',
            'is_active' => true,
        ])->assertCreated()->json('data');

        return FormAccessField::query()->findOrFail($created['id']);
    }

    private function hrUser(): User
    {
        return $this->userWithPermissions(['view-hr-settings', 'view-roles', 'roles-crud']);
    }

    /**
     * @param  list<string>  $names
     */
    private function userWithPermissions(array $names): User
    {
        $permissions = [];
        foreach ($names as $name) {
            $permissions[] = Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        $user = User::query()->create([
            'name' => $names[0],
            'username' => str_replace('|', '.', $names[0]).uniqid(),
            'email' => uniqid('custom-field-').'@example.com',
            'password' => bcrypt('secret'),
        ]);
        $user->givePermissionTo($permissions);

        return $user;
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
