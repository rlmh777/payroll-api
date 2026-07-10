<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_holiday', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('startDate');
            $table->date('endDate');
            $table->string('name', 1024);
            $table->decimal('payMultiplier', 5, 2)->default(1);
            $table->boolean('isActive')->default(true);
            $table->timestamps();

            $table->index(['startDate', 'endDate']);
        });

        if (!Schema::hasTable('calendar')) {
            Schema::create('scheduled_work', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->date('startDate');
                $table->date('endDate');
                $table->time('startTime')->nullable();
                $table->time('endTime')->nullable();
                $table->foreignUuid('employeeId')->nullable()->constrained('employee')->nullOnDelete();
                $table->foreignId('departmentId')->nullable()->constrained('department')->nullOnDelete();
                $table->foreignId('worksiteId')->nullable()->constrained('worksite')->nullOnDelete();
                $table->string('description', 1024);
                $table->decimal('rate', 5, 2)->default(1);
                $table->boolean('includeLunchHour')->default(false);
                $table->timestamps();

                $table->index(['employeeId', 'startDate', 'endDate']);
            });

            return;
        }

        $now = now();

        $holidayRows = DB::table('calendar')->where('type', 'holiday')->get();
        foreach ($holidayRows as $row) {
            $startDate = $row->startDate ?? $row->date;
            $endDate = $row->endDate ?? $startDate;

            DB::table('public_holiday')->insert([
                'id' => (string) Str::uuid(),
                'startDate' => $startDate,
                'endDate' => $endDate,
                'name' => $row->description,
                'payMultiplier' => $row->rate ?? 1,
                'isActive' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('calendar')->where('type', '!=', 'work')->delete();

        Schema::rename('calendar', 'scheduled_work');

        Schema::table('scheduled_work', function (Blueprint $table) {
            if (Schema::hasColumn('scheduled_work', 'type')) {
                $table->dropColumn('type');
            }
            if (Schema::hasColumn('scheduled_work', 'calendar_group_id')) {
                $table->dropColumn('calendar_group_id');
            }
            if (Schema::hasColumn('scheduled_work', 'date')) {
                $table->dropColumn('date');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('scheduled_work')) {
            Schema::rename('scheduled_work', 'calendar');

            Schema::table('calendar', function (Blueprint $table) {
                $table->date('date')->nullable();
                $table->string('type', 32)->default('work');
                $table->uuid('calendar_group_id')->nullable();
            });

            DB::statement('UPDATE calendar SET date = "startDate" WHERE date IS NULL');
        }

        Schema::dropIfExists('public_holiday');
    }
};
