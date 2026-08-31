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

        $launcher = $modules
            ->filter(fn (array $module) => $module['enabled'])
            ->values()
            ->all();

        $moduleMenus = [];
        if ($enabledCodes !== null) {
            foreach ($enabledCodes as $code) {
                $moduleMenus[$code] = $this->filterTreeByModule($menuTree, $code);
            }
        }

        return [
            'modules' => $modules->values()->all(),
            'launcher' => $launcher,
            'menuTree' => $menuTree,
            'moduleMenus' => $moduleMenus,
        ];
    }

    private function filterTreeByModule(array $tree, string $moduleCode): array
    {
        return array_values(array_filter($tree, function (array $node) use ($moduleCode) {
            return ($node['module_code'] ?? null) === $moduleCode;
        }));
    }
}
