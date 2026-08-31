<?php

namespace App\Services;

/**
 * Canonical system menu definitions synced by modules:sync-menus.
 */
class ModuleMenuCatalog
{
    public static function definitions(): array
    {
        return [
            [
                'system_key' => 'core.dashboard',
                'module_code' => 'payroll',
                'title' => 'Dashboard',
                'route' => '/',
                'icon' => 'dashboard',
                'permission' => 'view-dashboard',
                'order' => 1,
                'type' => 'menu',
            ],
            [
                'system_key' => 'core.reports',
                'module_code' => 'payroll',
                'title' => 'Reports',
                'route' => '/payroll/reports',
                'icon' => 'assessment',
                'permission' => 'view-reports',
                'order' => 8,
                'type' => 'menu',
            ],
            [
                'system_key' => 'hr.employees',
                'module_code' => 'payroll',
                'title' => 'Employees',
                'route' => '/payroll/employees',
                'icon' => 'groups',
                'permission' => 'view-employees',
                'order' => 2,
                'type' => 'menu',
            ],
            [
                'system_key' => 'hr.scheduler',
                'module_code' => 'payroll',
                'title' => 'Scheduler',
                'route' => '/payroll/scheduler',
                'icon' => 'calendar_month',
                'permission' => 'view-calendars',
                'order' => 3,
                'type' => 'menu',
            ],
            [
                'system_key' => 'hr.timesheet',
                'module_code' => 'payroll',
                'title' => 'Timesheet',
                'route' => '/payroll/timesheet',
                'icon' => 'schedule',
                'permission' => 'view-timesheets',
                'order' => 4,
                'type' => 'menu',
            ],
            [
                'system_key' => 'hr.leaves',
                'module_code' => 'payroll',
                'title' => 'Leaves',
                'route' => '/payroll/leaves',
                'icon' => 'event_busy',
                'permission' => 'view-leave',
                'order' => 5,
                'type' => 'menu',
            ],
            [
                'system_key' => 'payroll.root',
                'module_code' => 'payroll',
                'title' => 'Payroll',
                'route' => '/payroll',
                'icon' => 'payments',
                'permission' => 'view-payroll',
                'order' => 6,
                'type' => 'menu',
            ],
            [
                'system_key' => 'admin.settings',
                'module_code' => 'payroll',
                'title' => 'Settings',
                'route' => '/payroll/settings',
                'icon' => 'settings',
                'permission' => 'view-settings',
                'order' => 7,
                'type' => 'menu',
            ],
            [
                'system_key' => 'admin.modules',
                'module_code' => 'payroll',
                'parent_system_key' => 'admin.settings',
                'title' => 'Modules',
                'route' => '/payroll/settings/modules',
                'icon' => 'apps',
                'permission' => 'manage-modules',
                'order' => 99,
                'type' => 'submenu',
            ],
        ];
    }
}
