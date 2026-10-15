<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('historical_employee_deduction') || !Schema::hasColumn('historical_employee_deduction', 'payment_to_id')) {
            return;
        }

        DB::statement('ALTER TABLE historical_employee_deduction ALTER COLUMN payment_to_id DROP NOT NULL');
    }

    public function down(): void
    {
        if (!Schema::hasTable('historical_employee_deduction') || !Schema::hasColumn('historical_employee_deduction', 'payment_to_id')) {
            return;
        }

        DB::statement('ALTER TABLE historical_employee_deduction ALTER COLUMN payment_to_id SET NOT NULL');
    }
};
