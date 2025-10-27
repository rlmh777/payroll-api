<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Menu;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

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
        $dashboard = $this->createMenu('Dashboard', '/dashboard', 'fas fa-tachometer-alt', 'view-dashboard', 1);
        $accounts = $this->createMenu('Accounts', '/accounts', 'fas fa-wallet', 'view-accounts', 2);
        $reports = $this->createMenu('Reports', '/reports', 'fas fa-chart-bar', 'list-reports', 3);
        $settings = $this->createMenu('Settings', null, 'fas fa-cog', 'list-settings', 4);
        
        // Create Settings submenus
        $organization = $this->createSubMenu($settings->id, 'Organization', '/settings/organization', 'fas fa-building', 'view-organization', 1);
        $general = $this->createSubMenu($settings->id, 'General', '/settings/general', 'fas fa-sliders-h', 'view-general', 2);
        $calendars = $this->createSubMenu($settings->id, 'Calendars', '/settings/calendars', 'fas fa-calendar', 'view-calendars', 3);
        $holidays = $this->createSubMenu($settings->id, 'Holidays', '/settings/holidays', 'fas fa-calendar-times', 'view-holidays', 4);
        $rolesAndMenus = $this->createSubMenu($settings->id, 'Roles and Menus', '/settings/roles-menus', 'fas fa-users-cog', 'view-roles-menus', 5);
        $payItems = $this->createSubMenu($settings->id, 'Pay Items', '/settings/pay-items', 'fas fa-money-bill-wave', 'view-pay-items', 6);
        
        // Create General submenus (under Settings > General)
        $this->createSubMenu($general->id, 'Country', '/settings/general/country', 'fas fa-globe', 'country-crud', 1);
        $this->createSubMenu($general->id, 'District', '/settings/general/district', 'fas fa-map-marker-alt', 'district-crud', 2);
        $this->createSubMenu($general->id, 'Locality', '/settings/general/locality', 'fas fa-map-pin', 'locality-crud', 3);
        $this->createSubMenu($general->id, 'Institution', '/settings/general/institution', 'fas fa-university', 'institution-crud', 4);
        $this->createSubMenu($general->id, 'Honorific', '/settings/general/honorific', 'fas fa-user-tie', 'honorific-crud', 5);
        $this->createSubMenu($general->id, 'Relationship', '/settings/general/relationship', 'fas fa-users', 'relationship-crud', 6);
        $this->createSubMenu($general->id, 'Bank Account Type', '/settings/general/bank-account-type', 'fas fa-credit-card', 'bank-account-type-crud', 7);
        $this->createSubMenu($general->id, 'Calendar', '/settings/general/calendar', 'fas fa-calendar-alt', 'calendar-crud', 8);
        $this->createSubMenu($general->id, 'Calculation Mode', '/settings/general/calculation-mode', 'fas fa-calculator', 'calculation-mode-crud', 9);
        $this->createSubMenu($general->id, 'Degree', '/settings/general/degree', 'fas fa-graduation-cap', 'degree-crud', 10);
        $this->createSubMenu($general->id, 'Department', '/settings/general/department', 'fas fa-sitemap', 'department-crud', 11);
        $this->createSubMenu($general->id, 'Gender', '/settings/general/gender', 'fas fa-venus-mars', 'gender-crud', 12);
        $this->createSubMenu($general->id, 'Work Site', '/settings/general/worksite', 'fas fa-map', 'worksite-crud', 13);
        
        // Create Payroll menu with submenus
        $payroll = $this->createMenu('Payroll', null, 'fas fa-money-check-alt', 'view-payroll', 5);
        $this->createSubMenu($payroll->id, 'Overview', '/payroll/overview', 'fas fa-chart-pie', 'view-overview', 1);
        $this->createSubMenu($payroll->id, 'Employees', '/payroll/employees', 'fas fa-users', 'employees-crud', 2);
        $this->createSubMenu($payroll->id, 'Leave', '/payroll/leave', 'fas fa-calendar-check', 'leave-crud', 3);
        $this->createSubMenu($payroll->id, 'Timesheets', '/payroll/timesheets', 'fas fa-clock', 'timesheets-crud', 4);
        $this->createSubMenu($payroll->id, 'Pay Employees', '/payroll/pay-employees', 'fas fa-money-bill', 'pay-employees-crud', 5);
        $this->createSubMenu($payroll->id, 'Taxes & Filing', '/payroll/taxes-filing', 'fas fa-file-invoice', 'view-taxes', 6);
        
        // Create Roles and Menus submenus
        $this->createSubMenu($rolesAndMenus->id, 'Roles', '/settings/roles-menus/roles', 'fas fa-user-shield', 'roles-crud', 1);
        $this->createSubMenu($rolesAndMenus->id, 'Menu', '/settings/roles-menus/menu', 'fas fa-bars', 'menu-crud', 2);
        $this->createSubMenu($rolesAndMenus->id, 'Permissions', '/settings/roles-menus/permissions', 'fas fa-key', 'permissions-crud', 3);
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
            
            // Settings permissions
            'view-organization',
            'view-general',
            'view-calendars',
            'view-holidays',
            'view-roles-menus',
            'view-pay-items',
            
            // General settings CRUD permissions
            'country-crud',
            'district-crud',
            'locality-crud',
            'institution-crud',
            'honorific-crud',
            'relationship-crud',
            'bank-account-type-crud',
            'calendar-crud',
            'calculation-mode-crud',
            'degree-crud',
            'department-crud',
            'gender-crud',
            'worksite-crud',
            
            // Payroll permissions
            'view-overview',
            'employees-crud',
            'leave-crud',
            'timesheets-crud',
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
    }
}