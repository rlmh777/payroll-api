<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @return array<int, array<string, mixed>>
     */
    private function statuses(): array
    {
        return [
            ['code' => 'CANCELLED', 'name' => 'Cancelled', 'sortOrder' => 10, 'isTerminal' => true, 'requiresSupervisor' => false],
            ['code' => 'PENDING_SUPERVISOR_APPROVAL', 'name' => 'Pending supervisor approval', 'sortOrder' => 20, 'isTerminal' => false, 'requiresSupervisor' => true],
            ['code' => 'PENDING_APPROVAL', 'name' => 'Pending approval', 'sortOrder' => 30, 'isTerminal' => false, 'requiresSupervisor' => false],
            ['code' => 'SCHEDULED', 'name' => 'Scheduled', 'sortOrder' => 40, 'isTerminal' => false, 'requiresSupervisor' => false],
            ['code' => 'TAKEN', 'name' => 'Taken', 'sortOrder' => 50, 'isTerminal' => true, 'requiresSupervisor' => false],
            ['code' => 'REJECTED', 'name' => 'Rejected', 'sortOrder' => 60, 'isTerminal' => true, 'requiresSupervisor' => false],
        ];
    }

    public function up(): void
    {
        Schema::create('leave_status', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->unsignedSmallInteger('sortOrder')->default(0);
            $table->boolean('isTerminal')->default(false);
            $table->boolean('requiresSupervisor')->default(false);
            $table->timestamps();
        });

        $now = now();
        foreach ($this->statuses() as $status) {
            DB::table('leave_status')->insert(array_merge($status, [
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_status');
    }
};
