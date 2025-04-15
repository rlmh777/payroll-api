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
        Schema::create('allowance', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name',255);
            $table->boolean('isTaxable')->default(false);
            $table->boolean('isSocialSecurityDeductable')->default(false);
            $table->string('note',1024);
            $table->decimal('defaultAmount',total: 12, places: 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('allowance');
    }
};
