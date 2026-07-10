<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheet', function (Blueprint $table) {
            if (!Schema::hasColumn('timesheet', 'weeklySalary')) {
                $table->decimal('weeklySalary', 12, 2)->nullable()->after('baseSalary');
            }
        });
    }

    public function down(): void
    {
        Schema::table('timesheet', function (Blueprint $table) {
            if (Schema::hasColumn('timesheet', 'weeklySalary')) {
                $table->dropColumn('weeklySalary');
            }
        });
    }
};
