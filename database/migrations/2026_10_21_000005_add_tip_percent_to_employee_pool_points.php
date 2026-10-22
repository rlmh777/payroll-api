<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_pool_point', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_pool_point', 'tip_percent')) {
                $table->decimal('tip_percent', 8, 4)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('employee_pool_point', function (Blueprint $table) {
            if (Schema::hasColumn('employee_pool_point', 'tip_percent')) {
                $table->dropColumn('tip_percent');
            }
        });
    }
};
