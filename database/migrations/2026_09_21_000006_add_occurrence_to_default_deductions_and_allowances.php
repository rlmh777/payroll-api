<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addOccurrenceColumns('employee_default_deduction');
        $this->addOccurrenceColumns('employee_default_allowance');
        $this->backfillDeductionOccurrenceFromFrequency();
        $this->dropFrequencyId();
    }

    public function down(): void
    {
        if (Schema::hasTable('employee_default_deduction') && ! Schema::hasColumn('employee_default_deduction', 'frequencyId')) {
            Schema::table('employee_default_deduction', function (Blueprint $table) {
                $table->unsignedBigInteger('frequencyId')->nullable();
            });
            Schema::table('employee_default_deduction', function (Blueprint $table) {
                $table->foreign('frequencyId')->references('id')->on('payrate_frequency')->nullOnDelete();
            });
        }

        $this->dropOccurrenceColumns('employee_default_deduction');
        $this->dropOccurrenceColumns('employee_default_allowance');
    }

    private function addOccurrenceColumns(string $table): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            if (! Schema::hasColumn($table, 'occurrence')) {
                $blueprint->string('occurrence', 32)->default('every_payroll');
            }
            if (! Schema::hasColumn($table, 'occurrenceCycleLength')) {
                $blueprint->unsignedTinyInteger('occurrenceCycleLength')->nullable();
            }
            if (! Schema::hasColumn($table, 'occurrenceCycleOffset')) {
                $blueprint->unsignedTinyInteger('occurrenceCycleOffset')->nullable();
            }
        });
    }

    private function dropOccurrenceColumns(string $table): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            $drop = [];
            foreach (['occurrence', 'occurrenceCycleLength', 'occurrenceCycleOffset'] as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $drop[] = $column;
                }
            }
            if ($drop !== []) {
                $blueprint->dropColumn($drop);
            }
        });
    }

    private function backfillDeductionOccurrenceFromFrequency(): void
    {
        if (
            ! Schema::hasTable('employee_default_deduction')
            || ! Schema::hasColumn('employee_default_deduction', 'frequencyId')
            || ! Schema::hasTable('payrate_frequency')
        ) {
            return;
        }

        DB::statement(<<<'SQL'
            UPDATE employee_default_deduction AS deduction
            SET occurrence = 'first_of_month'
            FROM payrate_frequency AS frequency
            WHERE deduction."frequencyId" = frequency.id
              AND LOWER(TRIM(frequency.name)) LIKE '%month%'
              AND LOWER(TRIM(frequency.name)) NOT LIKE '%semi%'
        SQL);
    }

    private function dropFrequencyId(): void
    {
        if (! Schema::hasTable('employee_default_deduction') || ! Schema::hasColumn('employee_default_deduction', 'frequencyId')) {
            return;
        }

        DB::statement('ALTER TABLE employee_default_deduction DROP CONSTRAINT IF EXISTS employee_default_deduction_frequencyid_foreign');
        DB::statement('ALTER TABLE employee_default_deduction DROP CONSTRAINT IF EXISTS employee_default_deduction_frequencyId_foreign');

        Schema::table('employee_default_deduction', function (Blueprint $table) {
            $table->dropColumn('frequencyId');
        });
    }
};
