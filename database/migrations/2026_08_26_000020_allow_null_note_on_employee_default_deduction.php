<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_default_deduction') || ! Schema::hasColumn('employee_default_deduction', 'note')) {
            return;
        }

        DB::statement('ALTER TABLE employee_default_deduction ALTER COLUMN note DROP NOT NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_default_deduction') || ! Schema::hasColumn('employee_default_deduction', 'note')) {
            return;
        }

        DB::statement("UPDATE employee_default_deduction SET note = '' WHERE note IS NULL");
        DB::statement('ALTER TABLE employee_default_deduction ALTER COLUMN note SET NOT NULL');
    }
};
