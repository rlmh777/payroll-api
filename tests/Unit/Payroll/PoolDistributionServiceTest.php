<?php

namespace Tests\Unit\Payroll;

use App\Modules\Payroll\Services\PoolDistributionService;
use Tests\TestCase;

class PoolDistributionServiceTest extends TestCase
{
    public function test_sub_department_inherits_parent_share_until_overridden(): void
    {
        $service = $this->app->make(PoolDistributionService::class);

        $kitchen = 1;
        $grill = 2;
        $frontHouse = 10;
        $configuredPercents = [
            $kitchen => 30.0,
            $frontHouse => 70.0,
        ];
        $parentById = [
            $kitchen => null,
            $grill => $kitchen,
            $frontHouse => null,
        ];

        $this->assertSame($kitchen, $service->resolveShareDepartmentId($grill, $configuredPercents, $parentById));
        $this->assertSame($kitchen, $service->resolveShareDepartmentId($kitchen, $configuredPercents, $parentById));
        $this->assertSame($frontHouse, $service->resolveShareDepartmentId($frontHouse, $configuredPercents, $parentById));

        $configuredPercents[$grill] = 10.0;
        $this->assertSame($grill, $service->resolveShareDepartmentId($grill, $configuredPercents, $parentById));
        $this->assertNull($service->resolveShareDepartmentId(99, $configuredPercents, $parentById));
    }
}
