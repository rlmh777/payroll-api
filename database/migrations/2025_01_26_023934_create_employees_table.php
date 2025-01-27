<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('employee', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code',64);
            $table->string('internalId1',64)->nullable();
            $table->string('internalId2',64)->nullable();
            $table->foreignId('honorificId')->nullable()->constrained('honorific')->onDelete('cascade');
            $table->string('firstName',128);
            $table->string('middleName',128)->nullable();
            $table->string('lastName',128);
            $table->string('maidenName',128)->nullable();
            $table->date('birthdate');
            $table->string('address1',255);
            $table->string('address2',255)->nullable();
            $table->foreignId('localityId')->constrained('locality')->onDelete('cascade');
            $table->string('phone',12)->nullable();
            $table->string('email',255)->nullabe();
            $table->foreignId('genderId')->constrained('gender')->onDelete('cascade');
            $table->string('socialSecurityNumber',12);
            $table->string('taxIdentificationNumber',64)->nullable();
            $table->string('passportNumber',64)->nullable();
            $table->string('votersId',64)->nullable();
            $table->foreignId('citizenshipStatusId')->nullable()->constrained('citizenship_status')->onDelete('cascade');
            $table->foreignId('nationalityId')->nullable()->constrained('country')->onDelete('cascade');
            $table->foreignId('payrateFrequencyId')->constrained('payrate_frequency')->onDelete('cascade');
            $table->foreignId('paymentMethodId')->constrained('payment_method')->onDelete('cascade');
            $table->string('notes',255)->nullable();
            $table->string('picturePath',255)->nullable();
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
        Schema::dropIfExists('employee');
    }
};
