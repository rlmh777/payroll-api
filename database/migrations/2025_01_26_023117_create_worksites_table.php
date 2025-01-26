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
        Schema::create('worksite', function (Blueprint $table) {
            $table->id('id')->primary();
            $table->string('name', 512);
            $table->string('address1', 512);
            $table->string('address2',512)->nullable();
            $table->foreignId('localityId')->constrained('locality')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('worksite');
    }
};
