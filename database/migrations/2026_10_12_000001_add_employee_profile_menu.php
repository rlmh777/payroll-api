<?php

use App\Models\Menu;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! class_exists(Menu::class) || ! Schema::hasTable('menus')) {
            return;
        }

        $existing = Menu::query()->where('system_key', 'employee.profile')->first();
        if ($existing) {
            return;
        }

        Menu::query()->create([
            'id' => (string) Str::uuid(),
            'parent_id' => null,
            'title' => 'Profile',
            'route' => '/employee/profile',
            'icon' => 'badge',
            'permission' => null,
            'order' => 2,
            'type' => 'menu',
            'system_key' => 'employee.profile',
            'module_code' => 'employee',
            'source' => 'system',
            'is_active' => true,
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('menus')) {
            Menu::query()->where('system_key', 'employee.profile')->delete();
        }
    }
};
