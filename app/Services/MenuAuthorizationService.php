<?php

namespace App\Services;

use App\Models\Menu;
use App\Models\User;
use Illuminate\Support\Collection;

class MenuAuthorizationService
{
    public function menusForUser(User $user): Collection
    {
        $permissionNames = $user->getAllPermissions()->pluck('name');

        if ($user->hasRole('super-admin')) {
            return Menu::query()
                ->active()
                ->orderBy('order')
                ->get();
        }

        return Menu::query()
            ->active()
            ->whereIn('permission', $permissionNames)
            ->orderBy('order')
            ->get()
            ->filter(function (Menu $menu) use ($permissionNames) {
                if ($menu->permission && $permissionNames->contains($menu->permission)) {
                    return true;
                }

                return false;
            })
            ->values();
    }

    public function menuTreeForUser(User $user): array
    {
        $menus = $this->menusForUser($user);
        $allowedIds = $menus->pluck('id');

        $byId = $menus->keyBy('id');

        foreach ($menus as $menu) {
            if ($menu->parent_id && $allowedIds->contains($menu->parent_id)) {
                continue;
            }

            if ($menu->parent_id && !$allowedIds->contains($menu->parent_id)) {
                $parent = Menu::query()->find($menu->parent_id);
                if ($parent && $parent->is_active) {
                    $byId->put($parent->id, $parent);
                }
            }
        }

        $items = $byId->values()->sortBy('order')->values();

        return $this->buildTree($items);
    }

    private function buildTree(Collection $menus): array
    {
        $map = $menus->mapWithKeys(function (Menu $menu) {
            return [
                $menu->id => [
                    'id' => $menu->id,
                    'parent_id' => $menu->parent_id,
                    'title' => $menu->title,
                    'route' => $menu->route,
                    'icon' => $menu->icon,
                    'permission' => $menu->permission,
                    'order' => $menu->order,
                    'is_active' => $menu->is_active,
                    'type' => $menu->type,
                    'children' => [],
                ],
            ];
        })->all();

        $tree = [];

        foreach ($map as $id => $item) {
            if ($item['parent_id'] && isset($map[$item['parent_id']])) {
                $map[$item['parent_id']]['children'][] = &$map[$id];
            } else {
                $tree[] = &$map[$id];
            }
        }

        $this->sortTree($tree);

        return $tree;
    }

    private function sortTree(array &$nodes): void
    {
        usort($nodes, fn ($a, $b) => $a['order'] <=> $b['order']);

        foreach ($nodes as &$node) {
            if (!empty($node['children'])) {
                $this->sortTree($node['children']);
            }
        }
    }
}
