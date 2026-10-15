<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('employee', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->unique()->constrained('users')->onDelete('set null');
            $table->string('code',64);
            $table->string('internalId1',64)->nullable();
            $table->string('internalId2',64)->nullable();
            $table->foreignId('honorificId')->nullable()->constrained('honorific')->onDelete('cascade');
            $table->string('firstName');
            $table->string('middleName')->nullable();
            $table->string('lastName');
            $table->string('maidenName')->nullable();
            $table->date('birthdate');
            $table->string('address1',255);
            $table->string('address2',255)->nullable();
            $table->foreignUuid('localityId')->constrained('locality')->onDelete('cascade');
            $table->string('phone',12)->nullable();
            $table->string('email',255)->nullabe();
            $table->foreignId('genderId')->constrained('gender')->onDelete('cascade');
            $table->string('socialSecurityNumber');
            $table->string('taxIdentificationNumber')->nullable();
            $table->string('passportNumber')->nullable();
            $table->string('votersId')->nullable();
            $table->foreignId('citizenshipStatusId')->nullable()->constrained('citizenship_status')->onDelete('cascade');
            $table->foreignUuid('nationalityId')->nullable()->constrained('country')->onDelete('cascade');
            $table->foreignId('payrateFrequencyId')->constrained('payrate_frequency')->onDelete('cascade');
            $table->foreignId('paymentMethodId')->constrained('payment_method')->onDelete('cascade');
            $table->string('notes')->nullable();
            $table->string('picturePath')->nullable();
            $table->text('health')->nullable();
            $table->text('unionMembership')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Use CASCADE to drop dependent foreign key constraints
        if (Schema::hasTable('employee')) {
            DB::statement('DROP TABLE IF EXISTS employee CASCADE');
        }
    }
};
