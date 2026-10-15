<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_security', function (Blueprint $table) {
            $table->decimal('weeklyEarningsEndRange', 10, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('social_security', function (Blueprint $table) {
            $table->decimal('weeklyEarningsEndRange', 10, 2)->nullable(false)->change();
        });
    }
};
