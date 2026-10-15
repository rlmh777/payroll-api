<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('employment_detail', 'payrateFrequencyId')) {
            Schema::table('employment_detail', function (Blueprint $table) {
                $table->dropForeign(['payrateFrequencyId']);
                $table->dropColumn('payrateFrequencyId');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('employment_detail', 'payrateFrequencyId')) {
            Schema::table('employment_detail', function (Blueprint $table) {
                $table->foreignId('payrateFrequencyId')
                    ->nullable()
                    ->constrained('payrate_frequency')
                    ->onDelete('cascade');
            });
        }
    }
};
