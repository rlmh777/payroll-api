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
        Schema::create('chart_of_account', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->string('description', 255);
            $table->string('code1', 24);
            $table->string('code2', 24);
            $table->uuid('parent_id')->nullable();
            $table->string('type', 20); // asset, liability, equity, revenue, expense
            $table->boolean('is_active')->default(true);
            $table->decimal('balance', 15, 2)->default(0);
            $table->integer('level')->default(1);
            $table->timestamps();

            $table->foreign('parent_id')
                  ->references('id')
                  ->on('chart_of_account')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chart_of_account');
    }
};
