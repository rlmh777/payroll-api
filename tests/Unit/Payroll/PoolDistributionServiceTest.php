<?php

namespace Tests\Unit\Payroll;

use App\Modules\Payroll\Services\PoolDistributionService;
use Illuminate\Validation\ValidationException;
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

    public function test_uploaded_shares_split_the_typed_total_and_keep_the_remainder_on_the_last_share(): void
    {
        $service = $this->app->make(PoolDistributionService::class);

        $amounts = $service->allocateByShares(100, [1, 1, 1]);

        $this->assertCount(3, $amounts);
        $this->assertEqualsWithDelta(100, array_sum($amounts), 0.0000001);
        $this->assertEqualsWithDelta(33.3333333333, $amounts[0], 0.0000001);
        $this->assertEqualsWithDelta(33.3333333333, $amounts[1], 0.0000001);
        $this->assertEqualsWithDelta(33.3333333334, $amounts[2], 0.0000001);

        $weighted = $service->allocateByShares(300, [2, 1]);
        $this->assertEqualsWithDelta(200, $weighted[0], 0.0000001);
        $this->assertEqualsWithDelta(100, $weighted[1], 0.0000001);
    }

    public function test_blank_file_amounts_split_the_typed_total_by_shares_or_percentages(): void
    {
        $service = $this->app->make(PoolDistributionService::class);

        $amounts = $service->allocateUploadedPayouts(1000, [
            ['weight' => 60, 'amount' => null],
            ['weight' => 40, 'amount' => null],
        ]);

        $this->assertEqualsWithDelta(600, $amounts[0], 0.0000001);
        $this->assertEqualsWithDelta(400, $amounts[1], 0.0000001);
    }

    public function test_file_amounts_are_kept_and_blank_rows_split_the_remainder(): void
    {
        $service = $this->app->make(PoolDistributionService::class);

        $amounts = $service->allocateUploadedPayouts(100, [
            ['weight' => 1, 'amount' => null],
            ['weight' => 1, 'amount' => 40],
            ['weight' => 2, 'amount' => null],
        ]);

        $this->assertEqualsWithDelta(20, $amounts[0], 0.0000001);
        $this->assertEqualsWithDelta(40, $amounts[1], 0.0000001);
        $this->assertEqualsWithDelta(40, $amounts[2], 0.0000001);

        $explicit = $service->allocateUploadedPayouts(0, [
            ['weight' => 1, 'amount' => 25],
            ['weight' => 3, 'amount' => 75],
        ]);
        $this->assertEqualsWithDelta(25, $explicit[0], 0.0000001);
        $this->assertEqualsWithDelta(75, $explicit[1], 0.0000001);
    }

    public function test_typed_total_cannot_be_smaller_than_amounts_on_the_file(): void
    {
        $service = $this->app->make(PoolDistributionService::class);

        $this->expectException(ValidationException::class);
        $service->allocateUploadedPayouts(50, [
            ['weight' => 1, 'amount' => 80],
            ['weight' => 1, 'amount' => null],
        ]);
    }
}
