<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('honorificId')->nullable()->constrained('honorific')->nullOnDelete();
            $table->string('firstName');
            $table->string('middleName')->nullable();
            $table->string('lastName');
            $table->string('maidenName')->nullable();
            $table->date('birthdate');
            $table->string('address1', 255);
            $table->string('address2', 255)->nullable();
            $table->foreignUuid('localityId')->constrained('locality')->cascadeOnDelete();
            $table->string('phone', 12)->nullable();
            $table->string('email', 255)->nullable();
            $table->foreignId('genderId')->constrained('gender')->cascadeOnDelete();
            $table->string('socialSecurityNumber');
            $table->date('socialSecurityExpirationDate')->nullable();
            $table->string('taxIdentificationNumber')->nullable();
            $table->string('passportNumber')->nullable();
            $table->string('votersId')->nullable();
            $table->foreignId('citizenshipStatusId')->nullable()->constrained('citizenship_status')->nullOnDelete();
            $table->foreignUuid('nationalityId')->nullable()->constrained('country')->nullOnDelete();
            $table->string('notes')->nullable();
            $table->string('picturePath')->nullable();
            $table->text('health')->nullable();
            $table->text('unionMembership')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person');
    }
};
