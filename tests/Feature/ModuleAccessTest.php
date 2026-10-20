<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Country;
use App\Models\District;
use App\Models\Locality;
use App\Models\Module;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ModuleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_module_blocks_api_even_with_permission(): void
    {
        $this->seedTenantModules(hrEnabled: false, adminEnabled: true);

        $user = $this->userWithPermissions(['view-vacancies', 'vacancies-crud']);
        Sanctum::actingAs($user);

        $this->getJson('/api/vacancies')
            ->assertForbidden()
            ->assertJsonPath('message', 'Forbidden. Required application module is disabled.')
            ->assertJsonPath('modules.0', 'hr');
    }

    public function test_enabled_module_allows_api_when_permitted(): void
    {
        $this->seedTenantModules(hrEnabled: true, adminEnabled: true);

        $user = $this->userWithPermissions(['view-vacancies', 'vacancies-crud']);
        Sanctum::actingAs($user);

        $this->getJson('/api/vacancies')->assertOk();
    }

    public function test_modules_endpoint_stays_reachable_when_admin_disabled(): void
    {
        $this->seedTenantModules(hrEnabled: false, adminEnabled: false);

        $user = $this->userWithPermissions(['manage-modules']);
        Sanctum::actingAs($user);

        $this->getJson('/api/modules')->assertOk();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function userWithPermissions(array $permissions): User
    {
        $user = User::query()->create([
            'name' => 'Module Tester',
            'username' => 'mod.'.uniqid(),
            'email' => uniqid('mod-').'@example.com',
            'password' => bcrypt('secret-pass'),
        ]);

        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    private function seedTenantModules(bool $hrEnabled, bool $adminEnabled): void
    {
        foreach ([
            ['code' => 'hr', 'title' => 'HR', 'sort_order' => 1],
            ['code' => 'admin', 'title' => 'Administration', 'sort_order' => 99],
            ['code' => 'payroll', 'title' => 'Payroll', 'sort_order' => 2],
            ['code' => 'employee', 'title' => 'Employee', 'sort_order' => 3],
        ] as $module) {
            Module::query()->firstOrCreate(
                ['code' => $module['code']],
                [
                    'title' => $module['title'],
                    'icon' => 'apps',
                    'default_route' => '/',
                    'is_core' => false,
                    'is_active' => true,
                    'sort_order' => $module['sort_order'],
                    'version' => '1.0.0',
                ],
            );
        }

        $company = $this->createCompany();

        foreach ([
            'hr' => $hrEnabled,
            'admin' => $adminEnabled,
            'payroll' => true,
            'employee' => true,
        ] as $code => $enabled) {
            CompanyModule::query()->updateOrCreate(
                [
                    'company_id' => $company->id,
                    'module_code' => $code,
                ],
                [
                    'enabled' => $enabled,
                    'enabled_at' => $enabled ? now() : null,
                    'enabled_by' => null,
                    'config' => null,
                ],
            );
        }
    }

    private function createCompany(): Company
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

        $accountType = AccountType::query()->firstOrCreate(
            ['name' => 'LIABILITY'],
            ['normal_balance' => 'CREDIT'],
        );

        $account = Account::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Wages Payable',
            'description' => 'Test',
            'code1' => '2100',
            'code2' => null,
            'balance' => 0,
            'account_type_id' => $accountType->id,
            'parent_id' => null,
        ]);

        $company = new Company([
            'legalName' => 'Test Co Ltd.',
            'alias' => 'Test Co',
            'socialSecurityNumber' => '0000001',
            'taxIdentificationNumber' => '',
            'logoPath' => '',
            'phoneNumber1' => '000-0000',
            'phoneNumber2' => '000-0000',
            'email' => 'test@example.com',
            'street' => '1 Main St',
            'localityId' => $locality->id,
            'logo' => '',
            'primaryColor' => '#1976D2',
            'secondaryColor' => '#26A69A',
        ]);
        $company->forceFill([
            'wagesPayableAccountId' => $account->id,
            'defaultBankAccountId' => null,
        ]);
        $company->save();

        return $company;
    }
}
