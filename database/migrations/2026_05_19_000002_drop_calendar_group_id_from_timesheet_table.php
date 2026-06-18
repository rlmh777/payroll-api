<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('timesheet', 'calendar_group_id')) {
            return;
        }

        Schema::table('timesheet', function (Blueprint $table) {
            try {
                $table->dropForeign(['calendar_group_id']);
            } catch (\Throwable $e) {
                // Foreign key might already be missing; continue.
            }

            $table->dropColumn('calendar_group_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('timesheet', 'calendar_group_id')) {
            return;
        }

        Schema::table('timesheet', function (Blueprint $table) {
            $table->foreignUuid('calendar_group_id')
                ->nullable()
                ->constrained('calendar_groups')
                ->nullOnDelete();
        });
    }
};

