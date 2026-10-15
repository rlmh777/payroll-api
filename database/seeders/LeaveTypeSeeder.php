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
            ['code' => 'VACATION', 'name' => 'Vacation', 'sortOrder' => 10, 'annual' => 20, 'accrual' => 'MONTHLY', 'isPaid' => true, 'affectsBalance' => true, 'requiresCertification' => false, 'uncertifiedDaysAllowed' => 0],
            ['code' => 'SICK', 'name' => 'Sick', 'sortOrder' => 20, 'annual' => 14, 'accrual' => 'UPFRONT', 'isPaid' => true, 'affectsBalance' => true, 'requiresCertification' => true, 'uncertifiedDaysAllowed' => 2],
            ['code' => 'SICK_UNCERTIFIED', 'name' => 'Sick (uncertified)', 'sortOrder' => 30, 'annual' => 2, 'accrual' => 'UPFRONT', 'isPaid' => true, 'affectsBalance' => true, 'requiresCertification' => false, 'uncertifiedDaysAllowed' => 0],
            ['code' => 'PTO', 'name' => 'Paid time off', 'sortOrder' => 40, 'annual' => 5, 'accrual' => 'UPFRONT', 'isPaid' => true, 'affectsBalance' => true, 'requiresCertification' => false, 'uncertifiedDaysAllowed' => 0],
            ['code' => 'PATERNITY', 'name' => 'Paternity', 'sortOrder' => 50, 'annual' => 5, 'accrual' => 'UPFRONT', 'isPaid' => true, 'affectsBalance' => true, 'requiresCertification' => false, 'uncertifiedDaysAllowed' => 0],
            ['code' => 'PROFESSIONAL', 'name' => 'Professional', 'sortOrder' => 60, 'annual' => 5, 'accrual' => 'UPFRONT', 'isPaid' => true, 'affectsBalance' => true, 'requiresCertification' => false, 'uncertifiedDaysAllowed' => 0],
            ['code' => 'WITHOUT_PAY', 'name' => 'Without pay', 'sortOrder' => 80, 'annual' => 0, 'accrual' => 'NONE', 'isPaid' => false, 'affectsBalance' => false, 'requiresCertification' => false, 'uncertifiedDaysAllowed' => 0],
        ];

        foreach ($types as $type) {
            $leaveType = LeaveType::query()->updateOrCreate(
                ['code' => $type['code']],
                [
                    'name' => $type['name'],
                    'isPaid' => $type['isPaid'],
                    'affectsBalance' => $type['affectsBalance'],
                    'requiresCertification' => $type['requiresCertification'],
                    'uncertifiedDaysAllowed' => $type['uncertifiedDaysAllowed'],
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
