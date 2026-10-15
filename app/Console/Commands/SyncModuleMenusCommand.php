<?php

namespace App\Console\Commands;

use App\Models\Menu;
use App\Services\ModuleMenuCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SyncModuleMenusCommand extends Command
{
    protected $signature = 'modules:sync-menus';

    protected $description = 'Upsert system menus from the module catalog without overwriting custom entries';

    public function handle(): int
    {
        $systemKeys = [];
        $parentIds = [];

        foreach (ModuleMenuCatalog::definitions() as $definition) {
            $systemKey = $definition['system_key'];
            $systemKeys[] = $systemKey;

            $parentId = null;
            if (! empty($definition['parent_system_key'])) {
                $parentId = $parentIds[$definition['parent_system_key']]
                    ?? Menu::query()->where('system_key', $definition['parent_system_key'])->value('id');
            }

            $payload = [
                'title' => $definition['title'],
                'route' => $definition['route'] ?? null,
                'icon' => $definition['icon'] ?? null,
                'permission' => $definition['permission'] ?? null,
                'order' => $definition['order'] ?? 0,
                'type' => $definition['type'] ?? 'submenu',
                'module_code' => $definition['module_code'],
                'source' => 'system',
                'is_active' => true,
                'parent_id' => $parentId,
            ];

            $menu = Menu::query()->where('system_key', $systemKey)->first();
            if ($menu) {
                if ($menu->source === 'custom') {
                    $this->line("Skipping custom menu: {$systemKey}");
                } else {
                    $menu->update($payload);
                    $this->line("Updated system menu: {$systemKey}");
                }
            } else {
                $menu = Menu::query()->create([
                    ...$payload,
                    'id' => (string) Str::uuid(),
                    'system_key' => $systemKey,
                ]);
                $this->line("Created system menu: {$systemKey}");
            }

            $parentIds[$systemKey] = $menu->id;
        }

        $this->info('Module menu sync completed.');

        return self::SUCCESS;
    }
}
