<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employment_detail', function (Blueprint $table) {
            if (Schema::hasColumn('employment_detail', 'compensationMethod')) {
                $table->dropColumn('compensationMethod');
            }
            if (Schema::hasColumn('employment_detail', 'hourlyRate')) {
                $table->dropColumn('hourlyRate');
            }
            if (Schema::hasColumn('employment_detail', 'yearlyRate')) {
                $table->dropColumn('yearlyRate');
            }
            if (Schema::hasColumn('employment_detail', 'payscalePoint')) {
                $table->dropColumn('payscalePoint');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employment_detail', function (Blueprint $table) {
            if (!Schema::hasColumn('employment_detail', 'payscalePoint')) {
                $table->string('payscalePoint', 8)->nullable();
            }
            if (!Schema::hasColumn('employment_detail', 'hourlyRate')) {
                $table->decimal('hourlyRate', 12, 2)->default(0);
            }
            if (!Schema::hasColumn('employment_detail', 'yearlyRate')) {
                $table->decimal('yearlyRate', 12, 2)->default(0);
            }
            if (!Schema::hasColumn('employment_detail', 'compensationMethod')) {
                $table->string('compensationMethod', 32)->nullable();
            }
        });
    }
};
