<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auth_settings', function (Blueprint $table) {
            $table->json('username_patterns')->nullable()->after('username_pattern');
        });

        foreach (DB::table('auth_settings')->get(['id', 'username_pattern']) as $row) {
            $pattern = trim((string) ($row->username_pattern ?? ''));
            if ($pattern === '') {
                $pattern = 'first_last';
            }

            DB::table('auth_settings')->where('id', $row->id)->update([
                'username_patterns' => json_encode([$pattern]),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('auth_settings', function (Blueprint $table) {
            $table->dropColumn('username_patterns');
        });
    }
};
