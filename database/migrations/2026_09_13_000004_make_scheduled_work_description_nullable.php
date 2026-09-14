<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('scheduled_work') || ! Schema::hasColumn('scheduled_work', 'description')) {
            return;
        }

        Schema::table('scheduled_work', function (Blueprint $table) {
            $table->string('description', 1024)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_work') || ! Schema::hasColumn('scheduled_work', 'description')) {
            return;
        }

        Schema::table('scheduled_work', function (Blueprint $table) {
            $table->string('description', 1024)->nullable(false)->change();
        });
    }
};
