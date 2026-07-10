<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_certification', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employeeId')->constrained('employee')->cascadeOnDelete();
            $table->string('name');
            $table->string('issuingOrganization')->nullable();
            $table->string('credentialId')->nullable();
            $table->date('issuedOn')->nullable();
            $table->date('expiresOn')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_skill', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employeeId')->constrained('employee')->cascadeOnDelete();
            $table->string('name');
            $table->string('proficiencyLevel')->nullable();
            $table->decimal('yearsExperience', 4, 1)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_skill');
        Schema::dropIfExists('employee_certification');
    }
};
