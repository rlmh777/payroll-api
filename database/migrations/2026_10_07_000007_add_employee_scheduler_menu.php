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

        $this->ensureMenu([
            'parent_id' => null,
            'title' => 'Scheduler',
            'route' => '/payroll/scheduler',
            'icon' => 'calendar_month',
            'permission' => 'view-calendars',
            'order' => 2,
            'type' => 'menu',
            'system_key' => 'employee.scheduler',
            'module_code' => 'employee',
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('menus')) {
            Menu::query()->where('system_key', 'employee.scheduler')->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function ensureMenu(array $attributes): void
    {
        $existing = Menu::query()->where('system_key', $attributes['system_key'])->first();
        $payload = array_merge([
            'source' => 'system',
            'is_active' => true,
        ], $attributes);

        if ($existing) {
            return;
        }

        Menu::query()->create(array_merge($payload, [
            'id' => (string) Str::uuid(),
        ]));
    }
};
