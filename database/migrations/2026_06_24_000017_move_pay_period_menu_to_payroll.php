<?php

use App\Models\Menu;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $payrollMenu = Menu::query()
            ->where('route', '/payroll')
            ->where('type', 'menu')
            ->first();

        if (!$payrollMenu) {
            return;
        }

        Menu::query()
            ->where('route', '/settings/pay-period')
            ->update([
                'parent_id' => $payrollMenu->id,
                'route' => '/payroll/pay-period',
                'order' => 2,
            ]);
    }

    public function down(): void
    {
        $settingsMenu = Menu::query()
            ->where('route', '/settings')
            ->where('type', 'menu')
            ->first();

        if (!$settingsMenu) {
            return;
        }

        Menu::query()
            ->where('route', '/payroll/pay-period')
            ->update([
                'parent_id' => $settingsMenu->id,
                'route' => '/settings/pay-period',
                'order' => 11,
            ]);
    }
};
