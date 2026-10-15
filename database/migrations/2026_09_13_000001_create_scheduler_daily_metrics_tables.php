<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('scheduler_metric_definition')) {
            Schema::create('scheduler_metric_definition', function (Blueprint $table) {
                $table->id();
                $table->string('code', 64)->unique();
                $table->string('name');
                $table->string('short_label', 32)->nullable();
                $table->string('value_type', 16)->default('integer');
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('scheduler_daily_metric')) {
            Schema::create('scheduler_daily_metric', function (Blueprint $table) {
                $table->id();
                $table->foreignId('scheduler_metric_definition_id')
                    ->constrained('scheduler_metric_definition')
                    ->cascadeOnDelete();
                $table->date('date');
                $table->decimal('value', 12, 2)->nullable();
                $table->foreignUuid('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(
                    ['scheduler_metric_definition_id', 'date'],
                    'scheduler_daily_metric_definition_date_uq'
                );
                $table->index('date');
            });
        }

        $now = now();
        $defaults = [
            [
                'code' => 'guests',
                'name' => '# of guests',
                'short_label' => 'Guests',
                'value_type' => 'integer',
                'sort_order' => 1,
                'is_active' => false,
            ],
            [
                'code' => 'rooms_occupied',
                'name' => '# of rooms occupied',
                'short_label' => 'Rooms',
                'value_type' => 'integer',
                'sort_order' => 2,
                'is_active' => false,
            ],
        ];

        foreach ($defaults as $row) {
            $exists = DB::table('scheduler_metric_definition')->where('code', $row['code'])->exists();
            if ($exists) {
                continue;
            }

            DB::table('scheduler_metric_definition')->insert([
                ...$row,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduler_daily_metric');
        Schema::dropIfExists('scheduler_metric_definition');
    }
};
