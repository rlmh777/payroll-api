<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tax_calculator_run_lines')) {
            return;
        }

        Schema::table('tax_calculator_run_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('tax_calculator_run_lines', 'row_type')) {
                $table->string('row_type', 32)->default('account')->after('is_rollup');
            }
            if (! Schema::hasColumn('tax_calculator_run_lines', 'group_index')) {
                $table->unsignedInteger('group_index')->default(0)->after('row_type');
            }
            if (! Schema::hasColumn('tax_calculator_run_lines', 'include_in_tax')) {
                $table->boolean('include_in_tax')->default(true)->after('group_index');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tax_calculator_run_lines')) {
            return;
        }

        Schema::table('tax_calculator_run_lines', function (Blueprint $table) {
            foreach (['include_in_tax', 'group_index', 'row_type'] as $column) {
                if (Schema::hasColumn('tax_calculator_run_lines', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
