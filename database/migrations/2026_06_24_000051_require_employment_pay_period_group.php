<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (
            !Schema::hasTable('employment_detail')
            || !Schema::hasTable('pay_period_groups')
            || !Schema::hasColumn('employment_detail', 'defaultPayPeriodGroupId')
        ) {
            return;
        }

        $defaultGroupId = DB::table('pay_period_groups')
            ->where('isDefault', true)
            ->value('id');

        if (!$defaultGroupId) {
            $firstGroup = DB::table('pay_period_groups')->orderBy('created_at')->first();
            if ($firstGroup) {
                $defaultGroupId = $firstGroup->id;
                DB::table('pay_period_groups')
                    ->where('id', $defaultGroupId)
                    ->update([
                        'isDefault' => true,
                        'updated_at' => now(),
                    ]);
            }
        }

        if (!$defaultGroupId) {
            $defaultGroupId = (string) Str::uuid();
            DB::table('pay_period_groups')->insert([
                'id' => $defaultGroupId,
                'name' => 'Default Pay Period',
                'status' => 'active',
                'isDefault' => true,
                'rules' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('employment_detail')
            ->whereNull('defaultPayPeriodGroupId')
            ->update([
                'defaultPayPeriodGroupId' => $defaultGroupId,
                'updated_at' => now(),
            ]);

        DB::statement('ALTER TABLE employment_detail ALTER COLUMN "defaultPayPeriodGroupId" SET NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS employment_detail_pay_period_group_idx ON employment_detail ("defaultPayPeriodGroupId")');
        DB::statement('CREATE INDEX IF NOT EXISTS employment_detail_employee_dates_idx ON employment_detail ("employeeId", "startDate", "endDate")');
    }

    public function down(): void
    {
        if (
            !Schema::hasTable('employment_detail')
            || !Schema::hasColumn('employment_detail', 'defaultPayPeriodGroupId')
        ) {
            return;
        }

        DB::statement('ALTER TABLE employment_detail ALTER COLUMN "defaultPayPeriodGroupId" DROP NOT NULL');
    }
};
