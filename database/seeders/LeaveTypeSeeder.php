<?php

namespace Database\Seeders;

use App\Models\LeaveType;
use App\Models\LeaveTypePolicy;
use Illuminate\Database\Seeder;

class LeaveTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['code' => 'VACATION', 'name' => 'Vacation', 'sortOrder' => 10, 'annual' => 20, 'accrual' => 'MONTHLY', 'isPaid' => true, 'affectsBalance' => true, 'requiresCertification' => false],
            ['code' => 'SICK', 'name' => 'Sick', 'sortOrder' => 20, 'annual' => 14, 'accrual' => 'UPFRONT', 'isPaid' => true, 'affectsBalance' => true, 'requiresCertification' => true],
            ['code' => 'SICK_UNCERTIFIED', 'name' => 'Sick (uncertified)', 'sortOrder' => 30, 'annual' => 2, 'accrual' => 'UPFRONT', 'isPaid' => true, 'affectsBalance' => true, 'requiresCertification' => false],
            ['code' => 'PTO', 'name' => 'Paid time off', 'sortOrder' => 40, 'annual' => 5, 'accrual' => 'UPFRONT', 'isPaid' => true, 'affectsBalance' => true, 'requiresCertification' => false],
            ['code' => 'PATERNITY', 'name' => 'Paternity', 'sortOrder' => 50, 'annual' => 5, 'accrual' => 'UPFRONT', 'isPaid' => true, 'affectsBalance' => true, 'requiresCertification' => false],
            ['code' => 'PROFESSIONAL', 'name' => 'Professional', 'sortOrder' => 60, 'annual' => 5, 'accrual' => 'UPFRONT', 'isPaid' => true, 'affectsBalance' => true, 'requiresCertification' => false],
            ['code' => 'WITHOUT_PAY', 'name' => 'Without pay', 'sortOrder' => 80, 'annual' => 0, 'accrual' => 'NONE', 'isPaid' => false, 'affectsBalance' => false, 'requiresCertification' => false],
        ];

        foreach ($types as $type) {
            $leaveType = LeaveType::query()->updateOrCreate(
                ['code' => $type['code']],
                [
                    'name' => $type['name'],
                    'isPaid' => $type['isPaid'],
                    'affectsBalance' => $type['affectsBalance'],
                    'requiresCertification' => $type['requiresCertification'],
                    'isActive' => true,
                    'sortOrder' => $type['sortOrder'],
                ],
            );

            LeaveTypePolicy::query()->updateOrCreate(
                ['leaveTypeId' => $leaveType->id],
                [
                    'annualEntitlementDays' => $type['annual'],
                    'accrualMethod' => $type['accrual'],
                    'isEnabled' => true,
                ],
            );
        }
    }
}
