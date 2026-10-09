<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacancy_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vacancy_id')
                ->constrained('vacancies')
                ->cascadeOnDelete();
            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->timestamps();

            $table->index('vacancy_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacancy_attachments');
    }
};
