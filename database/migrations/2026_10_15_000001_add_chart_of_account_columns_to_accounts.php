<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounts')) {
            return;
        }

        Schema::table('accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('accounts', 'type')) {
                $table->string('type', 20)->nullable()->after('parent_id');
            }
            if (! Schema::hasColumn('accounts', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('type');
            }
            if (! Schema::hasColumn('accounts', 'level')) {
                $table->integer('level')->default(1)->after('is_active');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('accounts')) {
            return;
        }

        Schema::table('accounts', function (Blueprint $table) {
            if (Schema::hasColumn('accounts', 'level')) {
                $table->dropColumn('level');
            }
            if (Schema::hasColumn('accounts', 'is_active')) {
                $table->dropColumn('is_active');
            }
            if (Schema::hasColumn('accounts', 'type')) {
                $table->dropColumn('type');
            }
        });
    }
};
