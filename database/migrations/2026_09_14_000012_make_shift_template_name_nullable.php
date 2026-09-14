<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_template', function (Blueprint $table) {
            $table->dropUnique(['name']);
        });

        Schema::table('shift_template', function (Blueprint $table) {
            $table->string('name', 160)->nullable()->change();
        });

        // Normalize blank names to null so optional names stay unique when set.
        DB::table('shift_template')->where('name', '')->update(['name' => null]);

        Schema::table('shift_template', function (Blueprint $table) {
            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::table('shift_template', function (Blueprint $table) {
            $table->dropUnique(['name']);
        });

        DB::table('shift_template')->whereNull('name')->update(['name' => '']);

        Schema::table('shift_template', function (Blueprint $table) {
            $table->string('name', 160)->nullable(false)->change();
            $table->unique('name');
        });
    }
};
