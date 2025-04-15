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
        Schema::create('qualification', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employeeId')->constrained('employee')->onDelete('cascade');
            $table->foreignId('institutionId')->constrained('institution')->onDelete('cascade');
            $table->foreignId('degreeId')->constrained('degree')->onDelete('cascade');
            $table->date('from');
            $table->date('to')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('qualification');
    }
};
