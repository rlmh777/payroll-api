<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_leave_attachment', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employeeLeaveId')
                ->constrained('employee_leave')
                ->cascadeOnDelete();
            $table->string('filePath');
            $table->string('fileName');
            $table->string('mimeType')->nullable();
            $table->unsignedBigInteger('fileSize')->nullable();
            $table->timestamps();

            $table->index('employeeLeaveId');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_leave_attachment');
    }
};
