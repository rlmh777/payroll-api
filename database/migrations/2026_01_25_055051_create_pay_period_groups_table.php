<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pay_period_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->boolean('isDefault')->default(false);
            $table->text('rules')->nullable();
            $table->timestamps();
        });

        // Partial unique index: only one record can have isDefault = true
        // Create a unique index on a constant value when isDefault is true
        DB::statement('CREATE UNIQUE INDEX pay_period_groups_is_default_unique ON pay_period_groups ((1)) WHERE "isDefault" = true');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pay_period_groups');
    }
};
