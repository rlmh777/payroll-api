<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tax_calculator_runs')) {
            return;
        }

        if (! Schema::hasColumn('tax_calculator_runs', 'net_of_2251')) {
            Schema::table('tax_calculator_runs', function (Blueprint $table) {
                $table->decimal('net_of_2251', 18, 6)->default(0)->after('line_220');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tax_calculator_runs') && Schema::hasColumn('tax_calculator_runs', 'net_of_2251')) {
            Schema::table('tax_calculator_runs', function (Blueprint $table) {
                $table->dropColumn('net_of_2251');
            });
        }
    }
};
