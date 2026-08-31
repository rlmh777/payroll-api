<?php

namespace Database\Seeders;

use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class MenuSeeder extends Seeder
{
    private const MODULE_CODE = 'payroll';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->createPermissions();

        $this->ensureMenu([
            'title' => 'Dashboard',
            'route' => '/',
            'icon' => 'fas fa-tachometer-alt',
            'permission' => 'view-dashboard',
            'order' => 1,
            'type' => 'menu',
            'system_key' => 'core.dashboard',
        ]);

        $employees = $this->ensureMenu([
            'title' => 'Employees',
            'route' => '/payroll/employees',
            'icon' => 'fas fa-user-friends',
            'permission' => 'view-employees',
            'order' => 2,
            'type' => 'menu',
            'system_key' => 'hr.employees',
        ]);

        $this->ensureMenu([
            'parent_id' => $employees->id,
            'title' => 'Add Employee',
            'route' => '/payroll/employees/new',
            'icon' => 'person_add',
            'permission' => 'employees-crud',
            'order' => 1,
            'type' => 'submenu',
        ]);

        $this->ensureMenu([
            'parent_id' => $employees->id,
            'title' => 'Import Employees',
            'route' => '/payroll/employees/import',
            'icon' => 'upload_file',
            'permission' => 'employees-crud',
            'order' => 2,
            'type' => 'submenu',
        ]);

        $settings = $this->ensureMenu([
            'title' => 'Settings',
            'route' => '/payroll/settings',
            'icon' => 'fas fa-cog',
            'permission' => 'view-settings',
            'order' => 3,
            'type' => 'menu',
            'system_key' => 'admin.settings',
        ]);

        $this->ensureMenu([
            'title' => 'Scheduler',
            'route' => '/payroll/scheduler',
            'icon' => 'fas fa-calendar-week',
            'permission' => 'view-calendars',
            'order' => 4,
            'type' => 'menu',
            'system_key' => 'hr.scheduler',
        ]);

        $this->ensureMenu([
            'title' => 'Timesheet',
            'route' => '/payroll/timesheet',
            'icon' => 'fas fa-clock',
            'permission' => 'view-timesheets',
            'order' => 5,
            'type' => 'menu',
            'system_key' => 'hr.timesheet',
        ]);

        $leaves = $this->ensureMenu([
            'title' => 'Leaves',
            'route' => '/payroll/leaves',
            'icon' => 'fas fa-calendar-check',
            'permission' => 'view-leave',
            'order' => 6,
            'type' => 'menu',
            'system_key' => 'hr.leaves',
        ]);

        $leaveSubmenus = [
            ['Leave List', '/payroll/leaves/list', 'list_alt', 'view-leave', 1],
            ['Assign Leave', '/payroll/leaves/assign', 'event_available', 'leave-crud', 2],
            ['Request Leave', '/payroll/leaves/request', 'add_task', 'view-leave', 3],
            ['My Leave Usage', '/payroll/leaves/my-usage', 'pie_chart', 'view-leave', 4],
            ['Leave Calendar', '/payroll/leaves/calendar', 'calendar_month', 'view-leave', 5],
            ['Leave Entitlement', '/payroll/leaves/entitlement', 'card_membership', 'view-leave', 6],
            ['Leave Types', '/payroll/leaves/types', 'event_busy', 'view-leave-types', 7],
        ];

        foreach ($leaveSubmenus as [$title, $route, $icon, $permission, $order]) {
            $this->ensureMenu([
                'parent_id' => $leaves->id,
                'title' => $title,
                'route' => $route,
                'icon' => $icon,
                'permission' => $permission,
                'order' => $order,
                'type' => 'submenu',
            ]);
        }

        $general = $this->ensureMenu([
            'parent_id' => $settings->id,
            'title' => 'General',
            'route' => '/payroll/settings',
            'icon' => 'fas fa-sliders-h',
            'permission' => 'view-general',
            'order' => 1,
            'type' => 'submenu',
        ]);

        $settingsSubmenus = [
            ['Organization', '/payroll/settings/organization', 'fas fa-building', 'view-organization', 2],
            ['Accounts', '/payroll/settings/accounts', 'fas fa-wallet', 'view-accounts', 3],
            ['Account Mapping', '/payroll/settings/account-mapping', 'fas fa-project-diagram', 'view-account-mappings', 4],
            ['Social Security', '/payroll/settings/social-security', 'fa-solid fa-city', 'manager-social-security', 6],
            ['Personal Relief', '/payroll/settings/personal-relief', 'fa-solid fa-dollar-sign', 'manager-tax', 7],
            ['Payroll Settings', '/payroll/settings/payroll-settings', 'fa-solid fa-percent', 'manager-tax', 8],
            ['GST Calculator', '/payroll/settings/tax-calculator-accounts', 'fa-solid fa-file-invoice-dollar', 'view-tax-calculator', 9],
            ['Roles', '/payroll/settings/roles', 'fas fa-user-shield', 'view-roles', 9],
            ['Menu', '/payroll/settings/menu', 'fas fa-bars', 'view-menu', 10],
            ['Modules', '/payroll/settings/modules', 'apps', 'manage-modules', 11],
            ['Users', '/payroll/settings/users', 'fa-solid fa-users', 'manager-users', 12],
            ['Database Backup', '/payroll/settings/database-backup', 'fas fa-database', 'view-database-backup', 20],
        ];

        foreach ($settingsSubmenus as [$title, $route, $icon, $permission, $order]) {
            $this->ensureMenu([
                'parent_id' => $settings->id,
                'title' => $title,
                'route' => $route,
                'icon' => $icon,
                'permission' => $permission,
                'order' => $order,
                'type' => 'submenu',
                'system_key' => $route === '/payroll/settings/modules' ? 'admin.modules' : null,
            ]);
        }

        $generalSubmenus = [
            ['Country', '/payroll/settings/country', 'fas fa-globe', 'view-country', 1],
            ['District', '/payroll/settings/district', 'fas fa-map-marker-alt', 'view-district', 2],
            ['Locality', '/payroll/settings/locality', 'fas fa-map-pin', 'view-locality', 3],
            ['Institution', '/payroll/settings/institution', 'fas fa-university', 'view-institution', 4],
            ['Relationship', '/payroll/settings/relationship', 'fas fa-users', 'view-relationship', 6],
            ['Bank Account Type', '/payroll/settings/bank-account-type', 'fas fa-credit-card', 'view-bank-account-type', 7],
            ['Payroll Earning Codes', '/payroll/settings/payroll-earning-codes', 'fas fa-coins', 'view-payroll-earning-codes', 8],
            ['Pool Distribution', '/payroll/settings/pool-distribution-types', 'fas fa-chart-pie', 'view-pool-distribution-types', 9],
            ['Timesheet Templates', '/payroll/settings/timesheet-templates', 'fas fa-clock', 'view-timesheet-templates', 9],
            ['Degree', '/payroll/settings/degree', 'fas fa-graduation-cap', 'view-degree', 10],
            ['Job Titles', '/payroll/settings/job-titles', 'work', 'view-job-title', 16],
            ['Department', '/payroll/settings/department', 'fas fa-sitemap', 'view-department', 11],
            ['Work Site', '/payroll/settings/worksite', 'fas fa-map', 'view-worksite', 12],
            ['Public Holidays', '/payroll/settings/holidays', 'event', 'view-holidays', 13],
            ['Attendance', '/payroll/settings/attendance', 'schedule', 'view-attendance-settings', 14],
            ['Employee Groups', '/payroll/settings/employee-groups', 'groups', 'view-employee-groups', 15],
            ['Department Heads', '/payroll/settings/department-heads', 'supervisor_account', 'view-department-heads', 16],
        ];

        foreach ($generalSubmenus as [$title, $route, $icon, $permission, $order]) {
            $this->ensureMenu([
                'parent_id' => $general->id,
                'title' => $title,
                'route' => $route,
                'icon' => $icon,
                'permission' => $permission,
                'order' => $order,
                'type' => 'submenu',
            ]);
        }

        $payroll = $this->ensureMenu([
            'title' => 'Payroll',
            'route' => '/payroll',
            'icon' => 'fas fa-money-check-alt',
            'permission' => 'view-payroll',
            'order' => 7,
            'type' => 'menu',
            'system_key' => 'payroll.root',
        ]);

        $payrollSubmenus = [
            ['Overview', '/payroll/overview', 'fas fa-chart-pie', 'view-overview', 1],
            ['Pay Period', '/payroll/pay-period', 'fas fa-calendar', 'view-pay-period-groups', 2],
            ['Payroll Run', '/payroll/payroll-run', 'fas fa-money-check-alt', 'view-payroll', 3],
            ['Other Payments', '/payroll/allowances', 'fas fa-hand-holding-usd', 'view-payroll-allowances', 4],
            ['Day / trip work', '/payroll/day-work', 'fas fa-route', 'view-employee-day-work', 5],
            ['Generate Payslip', '/payroll/generate-payslip', 'fas fa-file-invoice-dollar', 'view-payroll', 6],
            ['Taxes & Filing', '/payroll/taxes-filing', 'fas fa-file-invoice', 'view-taxes', 7],
        ];

        foreach ($payrollSubmenus as [$title, $route, $icon, $permission, $order]) {
            $this->ensureMenu([
                'parent_id' => $payroll->id,
                'title' => $title,
                'route' => $route,
                'icon' => $icon,
                'permission' => $permission,
                'order' => $order,
                'type' => 'submenu',
            ]);
        }

        $this->ensureMenu([
            'title' => 'Reports',
            'route' => '/payroll/reports',
            'icon' => 'fas fa-chart-bar',
            'permission' => 'view-reports',
            'order' => 8,
            'type' => 'menu',
            'system_key' => 'core.reports',
        ]);
    }

    private function ensureMenu(array $attributes): Menu
    {
        $systemKey = $attributes['system_key'] ?? null;
        $route = $attributes['route'] ?? null;

        $existing = null;
        if ($systemKey) {
            $existing = Menu::query()->where('system_key', $systemKey)->first();
        }
        if (! $existing && $route) {
            $existing = Menu::query()->where('route', $route)->first();
        }

        $payload = array_merge([
            'module_code' => self::MODULE_CODE,
            'source' => 'system',
            'is_active' => true,
        ], $attributes);

        if ($existing) {
            $existing->update($payload);

            return $existing->fresh();
        }

        return Menu::create($payload);
    }

    private function createPermissions(): void
    {
        $permissions = [
            'view-dashboard',
            'view-employees',
            'view-accounts',
            'view-account-mappings',
            'account-mapping-crud',
            'view-database-backup',
            'database-backup-crud',
            'view-settings',
            'view-reports',
            'list-reports',
            'list-settings',
            'view-payroll',
            'view-timesheets',
            'view-organization',
            'view-general',
            'view-calendars',
            'view-holidays',
            'view-roles-menus',
            'view-roles',
            'view-menu',
            'view-pay-items',
            'view-leave',
            'view-leave-types',
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
            'view-pool-distribution-types',
            'pool-distribution-type-crud',
            'view-timesheet-templates',
            'view-degree',
            'view-job-title',
            'view-department',
            'view-worksite',
            'view-pay-period-groups',
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
            'job-title-crud',
            'department-crud',
            'gender-crud',
            'worksite-crud',
            'public-holiday-crud',
            'view-attendance-settings',
            'attendance-settings-crud',
            'view-department-heads',
            'department-head-crud',
            'view-employee-groups',
            'employee-groups-crud',
            'view-overview',
            'employees-crud',
            'leave-crud',
            'timesheets-crud',
            'view-clocking-logs',
            'import-clocking-logs',
            'pay-employees-crud',
            'view-payroll-allowances',
            'payroll-allowances-crud',
            'view-employee-day-work',
            'employee-day-work-crud',
            'view-taxes',
            'view-tax-calculator',
            'tax-calculator-crud',
            'roles-crud',
            'menu-crud',
            'permissions-crud',
            'manage-modules',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        $role = Role::firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'web',
        ]);
        $role->syncPermissions(Permission::query()->where('guard_name', 'web')->get());

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
