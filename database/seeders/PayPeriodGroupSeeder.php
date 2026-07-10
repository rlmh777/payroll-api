<?php

namespace Database\Seeders;

use App\Models\PayPeriodGroup;
use App\Models\PayPeriodSchedule;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class PayPeriodGroupSeeder extends Seeder
{
    public function run(): void
    {
        $monthlyGroup = PayPeriodGroup::updateOrCreate(
            ['name' => 'Monthly Payroll'],
            [
                'status' => 'active',
                'isDefault' => true,
                'rules' => null,
            ],
        );

        $biweeklyGroup = PayPeriodGroup::updateOrCreate(
            ['name' => 'Biweekly Payroll'],
            [
                'status' => 'active',
                'isDefault' => false,
                'rules' => null,
            ],
        );

        $this->seedMonthlySchedules($monthlyGroup->id);
        $this->seedBiweeklySchedules($biweeklyGroup->id);
    }

    private function seedMonthlySchedules(string $groupId): void
    {
        for ($month = 1; $month <= 12; $month++) {
            $start = Carbon::create(2026, $month, 1);
            $end = $start->copy()->endOfMonth();
            $pay = $end->copy()->addDay()->day(5);

            PayPeriodSchedule::updateOrCreate(
                [
                    'pay_period_group_id' => $groupId,
                    'start_date' => $start->toDateString(),
                ],
                [
                    'end_date' => $end->toDateString(),
                    'pay_date' => $pay->toDateString(),
                ],
            );
        }
    }

    private function seedBiweeklySchedules(string $groupId): void
    {
        $periodStart = Carbon::parse('2026-01-01');
        $yearEnd = Carbon::parse('2026-12-31');

        while ($periodStart->lte($yearEnd)) {
            $periodEnd = $periodStart->copy()->addDays(13);

            if ($periodEnd->gt($yearEnd)) {
                break;
            }

            $payDate = $periodEnd->copy()->addDays(3);

            PayPeriodSchedule::updateOrCreate(
                [
                    'pay_period_group_id' => $groupId,
                    'start_date' => $periodStart->toDateString(),
                ],
                [
                    'end_date' => $periodEnd->toDateString(),
                    'pay_date' => $payDate->toDateString(),
                ],
            );

            $periodStart = $periodEnd->copy()->addDay();
        }
    }
}
