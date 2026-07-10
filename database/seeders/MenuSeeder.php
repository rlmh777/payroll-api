<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;

class MenuSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create permissions first
        $this->createPermissions();

        // Create main navigation menus
        $this->createMenu('Dashboard', '/', 'fas fa-tachometer-alt', 'view-dashboard', 1);
        $this->createMenu('Employees', '/employees', 'fas fa-user-friends', 'view-employees', 2);
        $settings = $this->createMenu('Settings', '/settings', 'fas fa-cog', 'view-settings', 3);
        $this->createMenu('Scheduler', '/scheduler', 'fas fa-calendar-week', 'view-calendars', 4);
        if (!Menu::query()->where('route', '/timesheet')->where('type', 'menu')->exists()) {
            $this->createMenu('Timesheet', '/timesheet', 'fas fa-clock', 'view-timesheets', 5);
        }
        //$this->createMenu('Leaves', '/leaves', 'fas fa-calendar-check', 'view-leave', 8);
        // Create Settings submenus
        $general = $this->createSubMenu($settings->id, 'General', '/settings', 'fas fa-sliders-h', 'view-general', 1);
        $this->createSubMenu($settings->id, 'Organization', '/settings/organization', 'fas fa-building', 'view-organization', 2);
        $this->createSubMenu($settings->id, 'Accounts', '/settings/accounts', 'fas fa-wallet', 'view-accounts', 3);
        //$calendars = $this->createSubMenu($settings->id, 'Calendars', '/settings/calendars', 'fas fa-calendar', 'view-calendars', 5);
        $this->createSubMenu($settings->id, 'Social Security', '/settings/social-security', 'fa-solid fa-city', 'manager-social-security', 6);
        $this->createSubMenu($settings->id, 'Personal Relief', '/settings/personal-relief', 'fa-solid fa-dollar-sign', 'manager-tax', 7);
        $this->createSubMenu($settings->id, 'Payroll Settings', '/settings/payroll-settings', 'fa-solid fa-percent', 'manager-tax', 8);
        $this->createSubMenu($settings->id, 'Users', '/settings/users', 'fa-solid fa-users', 'manager-users', 9);
        // $holidays = $this->createSubMenu($settings->id, 'Holidays', '/settings/holidays', 'fas fa-calendar-times', 'view-holidays', 4);
        // $rolesAndMenus = $this->createSubMenu($settings->id, 'Roles and Menus', '/settings/roles-menus', 'fas fa-users-cog', 'view-roles-menus', 5);
        // $payItems = $this->createSubMenu($settings->id, 'Pay Items', '/settings/pay-items', 'fas fa-money-bill-wave', 'view-pay-items', 6);

        // Create General submenus (under Settings > General)
        $this->createSubMenu($general->id, 'Country', '/settings/country', 'fas fa-globe', 'view-country', 1);
        $this->createSubMenu($general->id, 'District', '/settings/district', 'fas fa-map-marker-alt', 'view-district', 2);
        $this->createSubMenu($general->id, 'Locality', '/settings/locality', 'fas fa-map-pin', 'view-locality', 3);
        $this->createSubMenu($general->id, 'Institution', '/settings/institution', 'fas fa-university', 'view-institution', 4);
        // $this->createSubMenu($general->id, 'Honorific', '/settings/honorific', 'fas fa-user-tie', 'view-honorific', 5);
        $this->createSubMenu($general->id, 'Relationship', '/settings/relationship', 'fas fa-users', 'view-relationship', 6);
        $this->createSubMenu($general->id, 'Bank Account Type', '/settings/bank-account-type', 'fas fa-credit-card', 'view-bank-account-type', 7);
        $this->createSubMenu($general->id, 'Payroll Earning Codes', '/settings/payroll-earning-codes', 'fas fa-coins', 'view-payroll-earning-codes', 8);
        $this->createSubMenu($general->id, 'Timesheet Templates', '/settings/timesheet-templates', 'fas fa-clock', 'view-timesheet-templates', 9);
        $this->createSubMenu($general->id, 'Degree', '/settings/degree', 'fas fa-graduation-cap', 'view-degree', 10);
        $this->createSubMenu($general->id, 'Department', '/settings/department', 'fas fa-sitemap', 'view-department', 11);
        // $this->createSubMenu($general->id, 'Gender', '/settings/gender', 'fas fa-venus-mars', 'view-gender', 12);
        $this->createSubMenu($general->id, 'Work Site', '/settings/worksite', 'fas fa-map', 'view-worksite', 12);
        $this->createSubMenu($general->id, 'Public Holidays', '/settings/holidays', 'event', 'view-holidays', 13);
        $this->createSubMenu($general->id, 'Attendance', '/settings/attendance', 'schedule', 'view-attendance-settings', 14);
        $this->createSubMenu($general->id, 'Department Heads', '/settings/department-heads', 'supervisor_account', 'view-department-heads', 15);
        $payroll = $this->createMenu('Payroll', '/payroll', 'fas fa-money-check-alt', 'view-payroll', 6);
        $this->createSubMenu($payroll->id, 'Overview', '/payroll/overview', 'fas fa-chart-pie', 'view-overview', 1);
        $this->createSubMenu($payroll->id, 'Pay Period', '/payroll/pay-period', 'fas fa-calendar', 'view-pay-period-groups', 2);
        $this->createSubMenu($payroll->id, 'Payroll Run', '/payroll/payroll-run', 'fas fa-money-check-alt', 'view-payroll', 3);
        $this->createSubMenu($payroll->id, 'Generate Payslip', '/payroll/generate-payslip', 'fas fa-file-invoice-dollar', 'view-payroll', 4);
        $this->createSubMenu($payroll->id, 'Taxes & Filing', '/payroll/taxes-filing', 'fas fa-file-invoice', 'view-taxes', 5);

        // Create Roles and Menus submenus
        $this->createSubMenu($settings->id, 'Roles', '/settings/roles', 'fas fa-user-shield', 'view-roles', 9);
        $this->createSubMenu($settings->id, 'Menu', '/settings/menu', 'fas fa-bars', 'view-menu', 10);
        $this->createMenu('Reports', '/reports', 'fas fa-chart-bar', 'view-reports', 7);
    }

    /**
     * Create a main menu item
     */
    private function createMenu($title, $route, $icon, $permission, $order)
    {
        return Menu::create([
            'title' => $title,
            'route' => $route,
            'icon' => $icon,
            'permission' => $permission,
            'order' => $order,
            'type' => 'menu',
            'is_active' => true
        ]);
    }

    /**
     * Create a submenu item
     */
    private function createSubMenu($parentId, $title, $route, $icon, $permission, $order)
    {
        return Menu::create([
            'parent_id' => $parentId,
            'title' => $title,
            'route' => $route,
            'icon' => $icon,
            'permission' => $permission,
            'order' => $order,
            'type' => 'submenu',
            'is_active' => true
        ]);
    }

    /**
     * Create all necessary permissions
     */
    private function createPermissions()
    {
        $permissions = [
            // Main navigation permissions
            'view-dashboard',
            'view-accounts',
            'list-reports',
            'list-settings',
            'view-payroll',
            'view-timesheets',

            // Settings permissions
            'view-organization',
            'view-general',
            'view-calendars',
            'view-holidays',
            'view-roles-menus',
            'view-pay-items',
            'view-leave',

            // General settings CRUD permissions
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
            'view-attendance-settings',
            'attendance-settings-crud',
            'view-department-heads',
            'department-head-crud',

            // Payroll permissions
            'view-overview',
            'employees-crud',
            'leave-crud',
            'timesheets-crud',
            'view-clocking-logs',
            'import-clocking-logs',
            'pay-employees-crud',
            'view-taxes',

            // Roles and Menus permissions
            'roles-crud',
            'menu-crud',
            'permissions-crud',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $role = Role::firstOrCreate(['name' => 'super-admin']);
        $role->givePermissionTo(Permission::all());
    }
}
