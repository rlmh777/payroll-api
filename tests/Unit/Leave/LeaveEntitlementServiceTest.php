<?php

namespace Tests\Unit\Leave;

use App\Enums\LeaveAccrualMethod;
use App\Models\EmploymentDetail;
use App\Services\Leave\LeaveEntitlementService;
use App\Services\Leave\LeaveSupervisorAuthorizationService;
use App\Services\Leave\LeaveWorkflowService;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LeaveEntitlementServiceTest extends TestCase
{
    private LeaveEntitlementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LeaveEntitlementService(
            new LeaveWorkflowService(new LeaveSupervisorAuthorizationService()),
        );
    }

    #[Test]
    public function monthly_vacation_accrues_from_contract_start(): void
    {
        $contract = new EmploymentDetail([
            'startDate' => '2025-01-15',
            'isActive' => true,
        ]);

        $accrued = $this->service->accruedDays(
            24,
            LeaveAccrualMethod::Monthly,
            $contract,
            Carbon::parse('2025-01-15'),
            Carbon::parse('2025-03-20'),
        );

        $this->assertEquals(6.0, $accrued);
    }

    #[Test]
    public function upfront_sick_entitlement_is_available_immediately(): void
    {
        $contract = new EmploymentDetail([
            'startDate' => '2025-06-01',
            'isActive' => true,
        ]);

        $accrued = $this->service->accruedDays(
            14,
            LeaveAccrualMethod::Upfront,
            $contract,
            Carbon::parse('2025-06-01'),
            Carbon::parse('2025-06-15'),
        );

        $this->assertEquals(14.0, $accrued);
    }
}
