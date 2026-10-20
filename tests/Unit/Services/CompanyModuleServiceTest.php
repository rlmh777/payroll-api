<?php

namespace Tests\Unit\Services;

use App\Services\CompanyModuleService;
use Tests\TestCase;

class CompanyModuleServiceTest extends TestCase
{
    public function test_required_modules_for_api_path_maps_resource_prefixes(): void
    {
        $service = app(CompanyModuleService::class);

        $this->assertSame(['hr'], $service->requiredModulesForApiPath('vacancies'));
        $this->assertSame(['hr'], $service->requiredModulesForApiPath('vacancies/abc'));
        $this->assertSame(['payroll'], $service->requiredModulesForApiPath('payroll-runs'));
        $this->assertSame(['hr', 'employee', 'payroll'], $service->requiredModulesForApiPath('employees/me'));
        $this->assertNull($service->requiredModulesForApiPath('modules'));
        $this->assertNull($service->requiredModulesForApiPath('session/navigation'));
        $this->assertNull($service->requiredModulesForApiPath(''));
    }
}
