<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_setting', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('clockRoundOffMinutes')->default(30);
            $table->timestamps();
        });

        DB::table('attendance_setting')->insert([
            'clockRoundOffMinutes' => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_setting');
    }
};
