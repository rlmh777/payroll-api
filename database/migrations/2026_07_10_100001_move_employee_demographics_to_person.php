<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const PERSON_COLUMNS = [
        'honorificId',
        'firstName',
        'middleName',
        'lastName',
        'maidenName',
        'birthdate',
        'address1',
        'address2',
        'localityId',
        'phone',
        'email',
        'genderId',
        'socialSecurityNumber',
        'taxIdentificationNumber',
        'passportNumber',
        'votersId',
        'citizenshipStatusId',
        'nationalityId',
        'notes',
        'picturePath',
        'health',
        'unionMembership',
    ];

    public function up(): void
    {
        Schema::table('employee', function (Blueprint $table) {
            $table->foreignUuid('person_id')->nullable()->after('id')->constrained('person')->cascadeOnDelete();
        });

        $employees = DB::table('employee')->orderBy('created_at')->get();

        foreach ($employees as $employee) {
            $personId = (string) Str::uuid();

            $personData = ['id' => $personId, 'created_at' => now(), 'updated_at' => now()];
            foreach (self::PERSON_COLUMNS as $column) {
                $personData[$column] = $employee->{$column};
            }

            DB::table('person')->insert($personData);

            DB::table('employee')
                ->where('id', $employee->id)
                ->update(['person_id' => $personId]);
        }

        Schema::table('employee', function (Blueprint $table) {
            $table->uuid('person_id')->nullable(false)->change();
            $table->unique('person_id');
        });

        Schema::table('employee', function (Blueprint $table) {
            foreach (self::PERSON_COLUMNS as $column) {
                if (Schema::hasColumn('employee', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('employee', function (Blueprint $table) {
            $table->foreignId('honorificId')->nullable()->constrained('honorific')->cascadeOnDelete();
            $table->string('firstName');
            $table->string('middleName')->nullable();
            $table->string('lastName');
            $table->string('maidenName')->nullable();
            $table->date('birthdate');
            $table->string('address1', 255);
            $table->string('address2', 255)->nullable();
            $table->foreignUuid('localityId')->constrained('locality')->cascadeOnDelete();
            $table->string('phone', 12)->nullable();
            $table->string('email', 255)->nullable();
            $table->foreignId('genderId')->constrained('gender')->cascadeOnDelete();
            $table->string('socialSecurityNumber');
            $table->string('taxIdentificationNumber')->nullable();
            $table->string('passportNumber')->nullable();
            $table->string('votersId')->nullable();
            $table->foreignId('citizenshipStatusId')->nullable()->constrained('citizenship_status')->cascadeOnDelete();
            $table->foreignUuid('nationalityId')->nullable()->constrained('country')->cascadeOnDelete();
            $table->string('notes')->nullable();
            $table->string('picturePath')->nullable();
            $table->text('health')->nullable();
            $table->text('unionMembership')->nullable();
        });

        $employees = DB::table('employee')
            ->join('person', 'employee.person_id', '=', 'person.id')
            ->select('employee.id as employee_id', 'person.*')
            ->get();

        foreach ($employees as $row) {
            $updates = [];
            foreach (self::PERSON_COLUMNS as $column) {
                $updates[$column] = $row->{$column};
            }

            DB::table('employee')->where('id', $row->employee_id)->update($updates);
        }

        Schema::table('employee', function (Blueprint $table) {
            $table->dropConstrainedForeignId('person_id');
        });

        DB::table('person')->delete();
    }
};
