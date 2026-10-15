<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_template', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 160);
            $table->json('segments');
            $table->boolean('include_lunch_hour')->default(false);
            $table->decimal('lunch_hour_hours', 4, 2)->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('name');
            $table->index('is_active');
        });

        Schema::create('shift_template_department', function (Blueprint $table) {
            $table->uuid('shift_template_id');
            $table->unsignedBigInteger('department_id');
            $table->timestamps();

            $table->primary(['shift_template_id', 'department_id']);
            $table->foreign('shift_template_id')
                ->references('id')
                ->on('shift_template')
                ->cascadeOnDelete();
            $table->foreign('department_id')
                ->references('id')
                ->on('department')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_template_department');
        Schema::dropIfExists('shift_template');
    }
};
