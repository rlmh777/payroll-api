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
            ->pluck('module_code')
            ->values();
    }

    public function isModuleEnabled(string $code): bool
    {
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

    public function modulesForCompany(): Collection
    {
        if (! $this->isAvailable()) {
            return collect();
        }

        $companyId = $this->companyId();

        $modules = Module::query()
            ->where('is_active', true)
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
