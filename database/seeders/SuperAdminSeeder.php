<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SuperAdminSeeder extends Seeder
{
    /**
     * Ensure johndoe@gmail.com is super-admin with every permission (menus + API).
     */
    public function run(): void
    {
        $adminUser = User::query()->updateOrCreate(
            ['email' => 'johndoe@gmail.com'],
            [
                'name' => 'John Doe',
                'password' => Hash::make('Password123!'),
            ]
        );

        $this->ensureCorePermissionsExist();

        $adminRole = Role::firstOrCreate(
            ['name' => 'super-admin', 'guard_name' => 'web']
        );

        $adminRole->syncPermissions(Permission::query()->where('guard_name', 'web')->get());

        $adminUser->syncRoles([$adminRole]);

        // Belt-and-suspenders: also assign every permission directly on the user,
        // so access works even if Spatie role cache is stale after seed.
        $adminUser->syncPermissions(Permission::query()->where('guard_name', 'web')->get());

        UserRole::query()
            ->where('user_id', $adminUser->id)
            ->where('role_id', '!=', $adminRole->id)
            ->delete();

        UserRole::firstOrCreate(
            [
                'user_id' => $adminUser->id,
                'role_id' => $adminRole->id,
            ],
            [
                'id' => (string) Str::uuid(),
            ]
        );

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    private function ensureCorePermissionsExist(): void
    {
        $permissions = [
            'view-dashboard',
            'view-employees',
            'employees-crud',
            'view-leave',
            'view-leave-types',
            'leave-crud',
            'view-accounts',
            'view-settings',
            'view-reports',
            'list-reports',
            'list-settings',
            'view-payroll',
            'view-timesheets',
            'timesheets-crud',
            'view-organization',
            'view-general',
            'view-calendars',
            'view-holidays',
            'view-roles-menus',
            'view-roles',
            'view-menu',
            'view-pay-items',
            'manager-users',
            'manager-tax',
            'manager-social-security',
            'view-country',
            'view-district',
            'view-locality',
            'view-institution',
            'view-relationship',
            'view-bank-account-type',
            'view-payroll-earning-codes',
            'view-timesheet-templates',
            'view-degree',
            'view-department',
            'view-worksite',
            'view-pay-period-groups',
            'view-overview',
            'view-taxes',
            'view-clocking-logs',
            'import-clocking-logs',
            'view-attendance-settings',
            'view-department-heads',
            'country-crud',
            'district-crud',
            'locality-crud',
            'institution-crud',
            'honorific-crud',
            'relationship-crud',
            'bank-account-type-crud',
            'payroll-earning-code-crud',
            'calendar-crud',
            'degree-crud',
            'department-crud',
            'gender-crud',
            'worksite-crud',
            'public-holiday-crud',
            'attendance-settings-crud',
            'department-head-crud',
            'pay-employees-crud',
            'roles-crud',
            'menu-crud',
            'permissions-crud',
        ];

        // Also ensure every permission referenced by menus exists.
        $menuPermissions = \App\Models\Menu::query()
            ->whereNotNull('permission')
            ->pluck('permission')
            ->filter()
            ->unique()
            ->values()
            ->all();

        foreach (array_unique([...$permissions, ...$menuPermissions]) as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission, 'guard_name' => 'web']
            );
        }
    }
}
