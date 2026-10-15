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
        Schema::create('deduction_type', function (Blueprint $table) {
            $table->id('id')->primary();
            $table->string('name', 64);
            $table->decimal('defaultAmount', total: 12, places: 2)->nullable();
            $table->string('note', 1024)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deduction_type');
    }
};
