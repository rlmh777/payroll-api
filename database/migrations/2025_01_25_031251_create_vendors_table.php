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
        Schema::create('vendor', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name',512)->unique();
            $table->string('phone',255);
            $table->string('email',255)->unique();
            $table->foreignUuid('bankId')->constrained('bank')->onDelete('cascade');
            $table->string('accountNumber',64);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vendor');
    }
};
