<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->json('allowed_ips')->nullable();
        });

        Schema::table('auth_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('lockout_max_attempts')->default(5);
            $table->unsignedSmallInteger('lockout_decay_minutes')->default(15);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['failed_login_attempts', 'locked_until', 'allowed_ips']);
        });

        Schema::table('auth_settings', function (Blueprint $table) {
            $table->dropColumn(['lockout_max_attempts', 'lockout_decay_minutes']);
        });
    }
};
