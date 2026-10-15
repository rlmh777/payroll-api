<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('department') || Schema::hasColumn('department', 'accountId')) {
            return;
        }

        Schema::table('department', function (Blueprint $table) {
            $table->foreignUuid('accountId')
                ->nullable()
                ->after('parentId')
                ->constrained('accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('department') || !Schema::hasColumn('department', 'accountId')) {
            return;
        }

        Schema::table('department', function (Blueprint $table) {
            $table->dropForeign(['accountId']);
            $table->dropColumn('accountId');
        });
    }
};
