<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_compensation', function (Blueprint $table) {
            if (!Schema::hasColumn('employee_compensation', 'requiresClocking')) {
                $table->boolean('requiresClocking')->default(true)->after('compensationMethod');
            }
        });

        $rows = DB::table('employee_compensation')->get();

        foreach ($rows as $row) {
            $method = strtoupper((string) ($row->compensationMethod ?? 'HOURLY'));
            $weeklyRate = (float) ($row->weeklyRate ?? 0);
            $yearlyRate = (float) ($row->yearlyRate ?? 0);
            $requiresClocking = true;

            [$newMethod, $requiresClocking] = match ($method) {
                'HOURLY' => ['HOURLY_OT', true],
                'HOURLY_NO_OT' => ['HOURLY_NO_OT', true],
                'HOURLY_OT' => ['HOURLY_OT', true],
                'WEEKLY_SALARY' => ['BASE_NO_OT', true],
                'WEEKLY_SALARY_OT' => ['BASE_OT', true],
                'SALARY_NO_CLOCK', 'BASE_SALARY' => ['BASE_NO_OT', false],
                'BASE_NO_OT' => ['BASE_NO_OT', (bool) ($row->requiresClocking ?? false)],
                'BASE_OT' => ['BASE_OT', true],
                default => ['HOURLY_OT', true],
            };

            if ($weeklyRate > 0 && $yearlyRate <= 0) {
                $yearlyRate = round($weeklyRate * 52, 2);
            }

            DB::table('employee_compensation')
                ->where('id', $row->id)
                ->update([
                    'compensationMethod' => $newMethod,
                    'requiresClocking' => $requiresClocking,
                    'yearlyRate' => $yearlyRate,
                    'weeklyRate' => 0,
                ]);
        }

        Schema::table('employee_compensation', function (Blueprint $table) {
            if (Schema::hasColumn('employee_compensation', 'weeklyRate')) {
                $table->dropColumn('weeklyRate');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employee_compensation', function (Blueprint $table) {
            if (!Schema::hasColumn('employee_compensation', 'weeklyRate')) {
                $table->decimal('weeklyRate', 12, 2)->default(0)->after('hourlyRate');
            }

            if (Schema::hasColumn('employee_compensation', 'requiresClocking')) {
                $table->dropColumn('requiresClocking');
            }
        });
    }
};
