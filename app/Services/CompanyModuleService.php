<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class CompanyModuleService
{
    public function isAvailable(): bool
    {
        return Schema::hasTable('modules') && Schema::hasTable('company_modules');
    }

    public function company(): ?Company
    {
        return Company::query()->orderBy('id')->first();
    }

    public function companyId(): ?int
    {
        return $this->company()?->id;
    }

    public function enabledModuleCodes(): ?Collection
    {
        if (config('modules.single_module_mode', true)) {
            return null;
        }

        if (! $this->isAvailable()) {
            return null;
        }

        $companyId = $this->companyId();
        if (! $companyId) {
            return Module::query()->where('is_active', true)->pluck('code');
        }

        return CompanyModule::query()
            ->where('company_id', $companyId)
            ->where('enabled', true)
            ->where('module_code', '!=', 'core')
            ->pluck('module_code')
            ->values();
    }

    public function isModuleEnabled(string $code): bool
    {
        if ($code === 'core') {
            return false;
        }

        if (! $this->isAvailable()) {
            return true;
        }

        $companyId = $this->companyId();
        if (! $companyId) {
            return Module::query()->where('code', $code)->where('is_active', true)->exists();
        }

        return CompanyModule::query()
            ->where('company_id', $companyId)
            ->where('module_code', $code)
            ->where('enabled', true)
            ->exists();
    }

    /**
     * True when at least one of the given module codes is enabled for the tenant.
     *
     * @param  string|list<string>  $codes
     */
    public function isAnyModuleEnabled(string|array $codes): bool
    {
        foreach ((array) $codes as $code) {
            if (is_string($code) && $code !== '' && $this->isModuleEnabled($code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Module codes that must be enabled for an API path (relative to /api).
     * Null means the path is not gated by module enablement.
     *
     * @return list<string>|null
     */
    public function requiredModulesForApiPath(string $path): ?array
    {
        $path = trim($path, '/');
        if ($path === '') {
            return null;
        }

        $segment = strtok($path, '/');
        if (! is_string($segment) || $segment === '') {
            return null;
        }

        $map = config('modules.api_resources', []);
        if (! is_array($map) || ! array_key_exists($segment, $map)) {
            return null;
        }

        $codes = $map[$segment];
        if (is_string($codes) && $codes !== '') {
            return [$codes];
        }

        if (! is_array($codes)) {
            return null;
        }

        $normalized = array_values(array_filter(
            $codes,
            static fn ($code) => is_string($code) && $code !== '',
        ));

        return $normalized === [] ? null : $normalized;
    }

    public function modulesForCompany(): Collection
    {
        if (! $this->isAvailable()) {
            return collect();
        }

        $companyId = $this->companyId();

        $modules = Module::query()
            ->where('is_active', true)
            ->where('code', '!=', 'core')
            ->orderBy('sort_order')
            ->get();

        $companyModules = $companyId
            ? CompanyModule::query()->where('company_id', $companyId)->get()->keyBy('module_code')
            : collect();

        return $modules->map(function (Module $module) use ($companyModules) {
            $companyModule = $companyModules->get($module->code);
            $enabled = (bool) ($companyModule?->enabled ?? false);

            return [
                'code' => $module->code,
                'title' => $module->title,
                'icon' => $module->icon,
                'default_route' => $module->default_route,
                'is_core' => $module->is_core,
                'enabled' => $enabled,
                'sort_order' => $module->sort_order,
                'version' => $module->version,
                'config' => $companyModule?->config,
            ];
        });
    }

    public function setEnabled(string $code, bool $enabled, ?User $user = null): CompanyModule
    {
        if ($code === 'core') {
            throw new \InvalidArgumentException('Core is not an application module.');
        }

        if (! $this->isAvailable()) {
            throw new \RuntimeException('Module system is not installed. Run database migrations first.');
        }

        $companyId = $this->companyId();
        if (! $companyId) {
            throw new \RuntimeException('No company configured for this deployment.');
        }

        $module = Module::query()->findOrFail($code);

        return CompanyModule::query()->updateOrCreate(
            [
                'company_id' => $companyId,
                'module_code' => $code,
            ],
            [
                'enabled' => $enabled,
                'enabled_at' => $enabled ? now() : null,
                'enabled_by' => $enabled ? $user?->id : null,
            ],
        );
    }
}
