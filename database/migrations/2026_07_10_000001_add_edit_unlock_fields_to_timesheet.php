<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('timesheet')) {
            return;
        }

        Schema::table('timesheet', function (Blueprint $table) {
            if (!Schema::hasColumn('timesheet', 'editUnlockedAt')) {
                $table->timestamp('editUnlockedAt')->nullable()->after('remarks');
            }

            if (!Schema::hasColumn('timesheet', 'editUnlockedBy')) {
                $table->foreignUuid('editUnlockedBy')
                    ->nullable()
                    ->after('editUnlockedAt')
                    ->constrained('employee')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('timesheet')) {
            return;
        }

        Schema::table('timesheet', function (Blueprint $table) {
            if (Schema::hasColumn('timesheet', 'editUnlockedBy')) {
                $table->dropForeign(['editUnlockedBy']);
                $table->dropColumn('editUnlockedBy');
            }

            if (Schema::hasColumn('timesheet', 'editUnlockedAt')) {
                $table->dropColumn('editUnlockedAt');
            }
        });
    }
};
