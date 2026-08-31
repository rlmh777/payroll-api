<?php

namespace Database\Seeders;

use App\Models\PayPeriodGroup;
use App\Models\PayPeriodSchedule;
use App\Modules\Payroll\Services\PayPeriodHelper;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class PayPeriodGroupSeeder extends Seeder
{
    public function run(): void
    {
        $defaultGroup = PayPeriodGroup::updateOrCreate(
            ['id' => 'dee02f24-d958-40cc-a188-b294b5be3e58'],
            [
                'name' => 'Default Pay Period',
                'status' => 'active',
                'isDefault' => false,
                'rules' => null,
            ],
        );

        $monthlyGroup = PayPeriodGroup::updateOrCreate(
            ['id' => '7248653f-198c-4017-8374-2e278ae1a8d9'],
            [
                'name' => 'Monthly Payroll',
                'status' => 'active',
                'isDefault' => true,
                'rules' => null,
            ],
        );

        $biweeklyGroup = PayPeriodGroup::updateOrCreate(
            ['id' => 'b66a4b09-88a8-468d-b5e4-5493c5179acc'],
            [
                'name' => 'Biweekly Payroll',
                'status' => 'active',
                'isDefault' => false,
                'rules' => null,
            ],
        );

        $this->replaceUpcomingSchedules($monthlyGroup->id, $this->monthlyPeriods());
        $this->replaceUpcomingSchedules($biweeklyGroup->id, $this->biweeklyPeriods());
        $this->replaceUpcomingSchedules($defaultGroup->id, []);
    }

    /**
     * @param  list<array{start_date: string, end_date: string, pay_date: string}>  $periods
     */
    private function replaceUpcomingSchedules(string $groupId, array $periods): void
    {
        $kept = PayPeriodHelper::keepCurrentAndOneAhead($periods);
        $keptStarts = array_values(array_map(
            fn (array $period) => $period['start_date'],
            $kept,
        ));

        PayPeriodSchedule::query()
            ->where('pay_period_group_id', $groupId)
            ->when(
                $keptStarts !== [],
                fn ($query) => $query->whereNotIn('start_date', $keptStarts),
                fn ($query) => $query,
            )
            ->delete();

        foreach ($kept as $period) {
            PayPeriodSchedule::updateOrCreate(
                [
                    'pay_period_group_id' => $groupId,
                    'start_date' => $period['start_date'],
                ],
                [
                    'end_date' => $period['end_date'],
                    'pay_date' => $period['pay_date'],
                ],
            );
        }
    }

    /**
     * @return list<array{start_date: string, end_date: string, pay_date: string}>
     */
    private function monthlyPeriods(): array
    {
        $periods = [];
        $cursor = now()->startOfMonth()->subMonthsNoOverflow(1);

        for ($i = 0; $i < 4; $i++) {
            $start = $cursor->copy()->addMonthsNoOverflow($i);
            $end = $start->copy()->endOfMonth();
            $pay = $end->copy()->addDay()->day(5);

            $periods[] = [
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'pay_date' => $pay->toDateString(),
            ];
        }

        return $periods;
    }

    /**
     * @return list<array{start_date: string, end_date: string, pay_date: string}>
     */
    private function biweeklyPeriods(): array
    {
        $epoch = Carbon::parse('2026-01-01')->startOfDay();
        $today = now()->startOfDay();
        $index = (int) floor($epoch->diffInDays($today) / 14);
        $currentStart = $epoch->copy()->addDays(max(0, $index - 1) * 14);

        $periods = [];
        for ($i = 0; $i < 4; $i++) {
            $start = $currentStart->copy()->addDays($i * 14);
            $end = $start->copy()->addDays(13);
            $pay = $end->copy()->addDays(3);

            $periods[] = [
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'pay_date' => $pay->toDateString(),
            ];
        }

        return $periods;
    }
}
