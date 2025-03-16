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
        Schema::create('employee_contact', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('firstName');
            $table->string('middleName')->nullable();
            $table->string('lastName');
            $table->string('phoneNumber1');
            $table->string('phoneNumber2')->nullable();
            $table->string('email');
            $table->string('address1',512);
            $table->string('address2',512)->nullable();
            $table->foreignUuid('localityId')->constrained('locality')->onDelete('cascade');
            $table->foreignId('relationshipId')->constrained('relationship')->onDelete('cascade');
            $table->foreignUuid('employeeId')->constrained('employee')->onDelete('cascade');
            $table->boolean('isDependent')->default(false);
            $table->boolean('isProfessionalReference')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_contact');
    }
};
