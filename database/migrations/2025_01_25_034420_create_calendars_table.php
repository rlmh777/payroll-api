<?php

use App\Models\Calendar;
use Database\Seeders\CalendarGroupSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('calendar', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('date')->useCurrent();
            $table->string('description', 1024);
            $table->decimal('multiplier', total: 3, places: 2)->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calendar');
    }
};
