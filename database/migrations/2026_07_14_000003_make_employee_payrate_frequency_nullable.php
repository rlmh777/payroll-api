<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('employee', 'payrateFrequencyId')) {
            return;
        }

        Schema::table('employee', function (Blueprint $table) {
            $table->dropForeign(['payrateFrequencyId']);
        });

        Schema::table('employee', function (Blueprint $table) {
            $table->unsignedBigInteger('payrateFrequencyId')->nullable()->change();
            $table->foreign('payrateFrequencyId')
                ->references('id')
                ->on('payrate_frequency')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('employee', 'payrateFrequencyId')) {
            return;
        }

        Schema::table('employee', function (Blueprint $table) {
            $table->dropForeign(['payrateFrequencyId']);
        });

        Schema::table('employee', function (Blueprint $table) {
            $table->unsignedBigInteger('payrateFrequencyId')->nullable(false)->change();
            $table->foreign('payrateFrequencyId')
                ->references('id')
                ->on('payrate_frequency')
                ->cascadeOnDelete();
        });
    }
};
