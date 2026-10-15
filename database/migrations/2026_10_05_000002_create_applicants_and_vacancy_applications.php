<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applicants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('email');
            $table->string('phone', 32)->nullable();
            $table->string('address1', 255)->nullable();
            $table->string('address2', 255)->nullable();
            $table->uuid('locality_id')->nullable();
            $table->date('birthdate')->nullable();
            $table->unsignedBigInteger('gender_id')->nullable();
            $table->string('social_security_number')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('employee_id')->nullable();
            $table->timestamps();

            $table->unique('email');
            $table->foreign('locality_id')->references('id')->on('locality')->nullOnDelete();
            $table->foreign('gender_id')->references('id')->on('gender')->nullOnDelete();
            $table->foreign('employee_id')->references('id')->on('employee')->nullOnDelete();
        });

        Schema::create('vacancy_applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('vacancy_id');
            $table->uuid('applicant_id');
            $table->text('cover_letter')->nullable();
            $table->string('resume_path')->nullable();
            $table->string('resume_name')->nullable();
            $table->string('source', 32)->default('public');
            $table->string('status', 32)->default('received');
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();

            $table->unique(['vacancy_id', 'applicant_id']);
            $table->foreign('vacancy_id')->references('id')->on('vacancies')->cascadeOnDelete();
            $table->foreign('applicant_id')->references('id')->on('applicants')->cascadeOnDelete();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacancy_applications');
        Schema::dropIfExists('applicants');
    }
};
