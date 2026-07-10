<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_compensation', function (Blueprint $table) {
            if (!Schema::hasColumn('employee_compensation', 'payscale')) {
                $table->string('payscale', 16)->nullable()->after('yearlyRate');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employee_compensation', function (Blueprint $table) {
            if (Schema::hasColumn('employee_compensation', 'payscale')) {
                $table->dropColumn('payscale');
            }
        });
    }
};
