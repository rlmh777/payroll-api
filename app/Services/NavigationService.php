<?php

namespace App\Services;

use App\Models\User;

class NavigationService
{
    public function __construct(
        private readonly CompanyModuleService $companyModuleService,
        private readonly MenuAuthorizationService $menuAuthorizationService,
    ) {}

    public function forUser(User $user): array
    {
        $enabledCodes = $this->companyModuleService->enabledModuleCodes();
        $modules = $this->companyModuleService->modulesForCompany();
        $menuTree = $this->menuAuthorizationService->menuTreeForUser($user, $enabledCodes);

        $moduleCodes = $enabledCodes?->values()->all()
            ?? $modules->pluck('code')->filter()->values()->all();

        $moduleMenus = [];
        foreach ($moduleCodes as $code) {
            $moduleMenus[$code] = $this->filterTreeByModule($menuTree, (string) $code);
        }

        $launcher = $modules
            ->filter(fn (array $module) => $module['enabled'])
            ->filter(fn (array $module) => ! empty($moduleMenus[$module['code']] ?? []))
            ->values()
            ->all();

        return [
            'modules' => $modules->values()->all(),
            'launcher' => $launcher,
            'menuTree' => $menuTree,
            'moduleMenus' => $moduleMenus,
        ];
    }

    /**
     * Application codes the user may open (enabled modules with authorized menus).
     *
     * @return list<string>
     */
    public function accessibleModuleCodes(User $user): array
    {
        $navigation = $this->forUser($user);
        $fromLauncher = collect($navigation['launcher'] ?? [])
            ->pluck('code')
            ->filter()
            ->map(fn ($code) => (string) $code)
            ->values()
            ->all();

        if ($fromLauncher !== []) {
            return $fromLauncher;
        }

        return collect($navigation['moduleMenus'] ?? [])
            ->filter(fn ($menus) => is_array($menus) && $menus !== [])
            ->keys()
            ->reject(fn ($code) => $code === 'core' || $code === '')
            ->map(fn ($code) => (string) $code)
            ->values()
            ->all();
    }

    private function filterTreeByModule(array $tree, string $moduleCode): array
    {
        return array_values(array_filter($tree, function (array $node) use ($moduleCode) {
            return ($node['module_code'] ?? null) === $moduleCode;
        }));
    }
}
