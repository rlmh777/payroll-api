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
        Schema::create('personal_relief', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->decimal('startRange', 10, 2);
            $table->decimal('endRange', 10, 2);
            $table->decimal('personalRelief', 10, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('personal_relief');
    }
};

