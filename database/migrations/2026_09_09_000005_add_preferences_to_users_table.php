<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'preferences')) {
                $table->json('preferences')->nullable()->after('remember_token');
            }
        });

        $default = json_encode(['default_module' => 'payroll']);

        DB::table('users')
            ->whereNull('preferences')
            ->update(['preferences' => $default]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'preferences')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('preferences');
        });
    }
};
