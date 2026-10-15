<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_title', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->unique();
            $table->string('payScale', 255)->nullable();
            $table->string('jobDescriptionPath', 1024)->nullable();
            $table->timestamps();
        });

        if (!Schema::hasColumn('employment_detail', 'jobTitleId')) {
            Schema::table('employment_detail', function (Blueprint $table) {
                $table->foreignId('jobTitleId')
                    ->nullable()
                    ->after('isActive')
                    ->constrained('job_title')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasColumn('employment_detail', 'jobTitle')) {
            $titles = DB::table('employment_detail')
                ->select('jobTitle')
                ->whereNotNull('jobTitle')
                ->where('jobTitle', '!=', '')
                ->distinct()
                ->pluck('jobTitle');

            $now = now();
            foreach ($titles as $title) {
                $name = trim((string) $title);
                if ($name === '') {
                    continue;
                }

                $jobTitleId = DB::table('job_title')->where('name', $name)->value('id');
                if (!$jobTitleId) {
                    $jobTitleId = DB::table('job_title')->insertGetId([
                        'name' => $name,
                        'payScale' => null,
                        'jobDescriptionPath' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                DB::table('employment_detail')
                    ->where('jobTitle', $title)
                    ->update(['jobTitleId' => $jobTitleId]);
            }

            Schema::table('employment_detail', function (Blueprint $table) {
                $table->dropColumn('jobTitle');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('employment_detail', 'jobTitle')) {
            Schema::table('employment_detail', function (Blueprint $table) {
                $table->string('jobTitle', 255)->nullable()->after('isActive');
            });
        }

        if (Schema::hasColumn('employment_detail', 'jobTitleId')) {
            $rows = DB::table('employment_detail')
                ->leftJoin('job_title', 'employment_detail.jobTitleId', '=', 'job_title.id')
                ->select('employment_detail.id', 'job_title.name')
                ->get();

            foreach ($rows as $row) {
                DB::table('employment_detail')
                    ->where('id', $row->id)
                    ->update(['jobTitle' => $row->name]);
            }

            Schema::table('employment_detail', function (Blueprint $table) {
                $table->dropConstrainedForeignId('jobTitleId');
            });
        }

        Schema::dropIfExists('job_title');
    }
};
