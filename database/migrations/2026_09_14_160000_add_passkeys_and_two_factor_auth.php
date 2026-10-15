<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webauthn_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('name')->nullable();
            $table->text('credential_id');
            $table->string('credential_id_hash', 64);
            $table->text('public_key');
            $table->uuid('aaguid')->nullable();
            $table->unsignedBigInteger('counter')->default(0);
            $table->json('transports')->nullable();
            $table->string('attestation_type')->default('none');
            $table->json('trust_path')->nullable();
            $table->boolean('backup_eligible')->nullable();
            $table->boolean('backup_status')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique('credential_id_hash');
            $table->index('user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            // null = inherit company policy; true = force; false = exempt
            $table->boolean('two_factor_required')->nullable()->after('two_factor_confirmed_at');
        });

        Schema::create('auth_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // off | optional | required
            $table->string('two_factor_policy', 20)->default('off');
            $table->boolean('passkeys_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_settings');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
                'two_factor_required',
            ]);
        });

        Schema::dropIfExists('webauthn_credentials');
    }
};
