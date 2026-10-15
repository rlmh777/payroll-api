<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Core\Models\FormAccessField;
use App\Modules\Core\Models\FormAccessForm;
use App\Modules\Core\Services\FormAccessCatalog;
use App\Services\EmployeeFormAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeFormAccessCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_is_seeded_from_the_database(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/roles/employee-form-access/catalog')
            ->assertOk()
            ->assertJsonFragment(['key' => 'personal', 'label' => 'Personal Info'])
            ->assertJsonFragment(['key' => 'internalId1', 'label' => 'Internal Code 1']);
    }

    public function test_custom_catalog_fields_appear_for_role_access(): void
    {
        $form = FormAccessForm::query()->where('key', FormAccessForm::KEY_EMPLOYEE)->firstOrFail();
        FormAccessField::query()->create([
            'form_id' => $form->id,
            'tab_key' => 'personal',
            'key' => 'uniformSize',
            'label' => 'Uniform size',
            'sort' => 90,
            'is_system' => false,
            'is_active' => true,
        ]);
        app(FormAccessCatalog::class)->forget(FormAccessForm::KEY_EMPLOYEE);

        Sanctum::actingAs($this->admin());

        $this->getJson('/api/roles/employee-form-access/catalog')
            ->assertOk()
            ->assertJsonFragment(['key' => 'uniformSize', 'label' => 'Uniform size']);

        $profile = app(EmployeeFormAccessService::class)->fullAccess();
        $this->assertSame('edit', $profile['fields']['uniformSize'] ?? null);

        $role = Role::query()->create(['name' => 'catalog-hr', 'guard_name' => 'web']);
        $this->putJson("/api/roles/{$role->id}/employee-form-access", [
            'tabs' => ['personal' => 'edit'],
            'fields' => ['uniformSize' => 'view'],
        ])->assertOk()->assertJsonPath('employee_form_access.fields.uniformSize', 'view');
    }

    private function admin(): User
    {
        $permissions = [];
        foreach (['view-roles', 'roles-crud'] as $name) {
            $permissions[] = Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        $user = User::query()->create([
            'name' => 'Role Admin',
            'username' => 'role.admin.'.uniqid(),
            'email' => uniqid('role-').'@example.com',
            'password' => bcrypt('secret'),
        ]);
        $user->givePermissionTo($permissions);

        return $user;
    }
}
