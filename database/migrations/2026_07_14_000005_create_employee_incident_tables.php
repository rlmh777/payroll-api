<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_incident', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employeeId')
                ->constrained('employee')
                ->cascadeOnDelete();
            $table->date('incidentDate');
            $table->date('reportedDate')->nullable();
            $table->string('incidentType', 64);
            $table->string('severity', 32);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 32)->default('REPORTED');
            $table->foreignUuid('reportedByEmployeeId')
                ->nullable()
                ->constrained('employee')
                ->nullOnDelete();
            $table->unsignedBigInteger('departmentId')->nullable();
            $table->unsignedBigInteger('worksiteId')->nullable();
            $table->string('actionTaken', 64)->nullable();
            $table->date('actionDate')->nullable();
            $table->date('followUpDate')->nullable();
            $table->text('resolutionNotes')->nullable();
            $table->boolean('employeeAcknowledged')->default(false);
            $table->timestamp('acknowledgedAt')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('departmentId')->references('id')->on('department')->nullOnDelete();
            $table->foreign('worksiteId')->references('id')->on('worksite')->nullOnDelete();
            $table->index(['employeeId', 'incidentDate']);
            $table->index('status');
            $table->index('severity');
        });

        Schema::create('employee_incident_attachment', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employeeIncidentId')
                ->constrained('employee_incident')
                ->cascadeOnDelete();
            $table->string('filePath');
            $table->string('fileName');
            $table->string('mimeType')->nullable();
            $table->unsignedBigInteger('fileSize')->nullable();
            $table->timestamps();

            $table->index('employeeIncidentId');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_incident_attachment');
        Schema::dropIfExists('employee_incident');
    }
};
