<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('job_title')) {
            return;
        }

        Schema::table('job_title', function (Blueprint $table) {
            if (!Schema::hasColumn('job_title', 'notes')) {
                $table->longText('notes')->nullable()->after('payScale');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('job_title') || !Schema::hasColumn('job_title', 'notes')) {
            return;
        }

        Schema::table('job_title', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
