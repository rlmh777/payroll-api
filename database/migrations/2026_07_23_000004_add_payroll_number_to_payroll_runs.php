<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_runs')) {
            return;
        }

        if (! Schema::hasColumn('payroll_runs', 'payroll_number')) {
            Schema::table('payroll_runs', function (Blueprint $table) {
                $table->unsignedInteger('payroll_number')->nullable()->after('status');
            });
        }

        $runs = DB::table('payroll_runs')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'payroll_number']);

        $number = 1;
        foreach ($runs as $run) {
            if ($run->payroll_number === null) {
                DB::table('payroll_runs')
                    ->where('id', $run->id)
                    ->update(['payroll_number' => $number]);
            }
            $number = max($number, (int) ($run->payroll_number ?? $number)) + 1;
        }

        // Re-number cleanly in creation order so values are sequential.
        $ordered = DB::table('payroll_runs')
            ->orderBy('created_at')
            ->orderBy('id')
            ->pluck('id');

        $number = 1;
        foreach ($ordered as $id) {
            DB::table('payroll_runs')
                ->where('id', $id)
                ->update(['payroll_number' => $number]);
            $number++;
        }

        DB::statement('ALTER TABLE payroll_runs ALTER COLUMN payroll_number SET NOT NULL');

        $indexExists = collect(DB::select(
            "SELECT 1 FROM pg_indexes WHERE tablename = 'payroll_runs' AND indexname = 'payroll_runs_payroll_number_unique'"
        ))->isNotEmpty();

        if (! $indexExists) {
            Schema::table('payroll_runs', function (Blueprint $table) {
                $table->unique('payroll_number');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('payroll_runs') || ! Schema::hasColumn('payroll_runs', 'payroll_number')) {
            return;
        }

        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropUnique(['payroll_number']);
            $table->dropColumn('payroll_number');
        });
    }
};
