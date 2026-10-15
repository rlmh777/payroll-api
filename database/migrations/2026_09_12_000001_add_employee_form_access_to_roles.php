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
        Schema::table('roles', function (Blueprint $table) {
            $table->json('employee_form_access')->nullable()->after('guard_name');
        });

        $guard = config('auth.defaults.guard', 'web');

        foreach (['hr', 'gm', 'accountant', 'payroll-accountant', 'admin', 'supervisor', 'employee'] as $roleName) {
            $exists = DB::table('roles')->where('name', $roleName)->where('guard_name', $guard)->exists();
            if (! $exists) {
                DB::table('roles')->insert([
                    'id' => (string) Str::uuid(),
                    'name' => $roleName,
                    'guard_name' => $guard,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $full = $this->fullAccess();
        $hr = $this->hrAccess();
        $employee = $this->employeeAccess();

        $byRole = [
            'admin' => $full,
            'gm' => $full,
            'accountant' => $full,
            'payroll-accountant' => $full,
            'hr' => $hr,
            'supervisor' => $hr,
            'employee' => $employee,
        ];

        foreach ($byRole as $name => $profile) {
            DB::table('roles')
                ->where('name', $name)
                ->where('guard_name', $guard)
                ->update([
                    'employee_form_access' => json_encode($profile),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('employee_form_access');
        });
    }

    /**
     * @return array{tabs: array<string, string>, fields: array<string, string>}
     */
    private function fullAccess(): array
    {
        $tabs = [];
        foreach ($this->tabKeys() as $tab) {
            $tabs[$tab] = 'edit';
        }

        $fields = [];
        foreach ($this->fieldKeys() as $field) {
            $fields[$field] = 'edit';
        }

        return ['tabs' => $tabs, 'fields' => $fields];
    }

    /**
     * @return array{tabs: array<string, string>, fields: array<string, string>}
     */
    private function hrAccess(): array
    {
        $tabs = [
            'personal' => 'edit',
            'address' => 'edit',
            'employment' => 'edit',
            'education' => 'edit',
            'payment_details' => 'hidden',
            'contact' => 'edit',
            'contracts' => 'edit',
            'compensation' => 'hidden',
            'documents' => 'view',
            'incidents' => 'edit',
            'time_travel' => 'hidden',
            'allowances' => 'hidden',
            'deductions' => 'hidden',
            'ss_benefit' => 'hidden',
        ];

        $fields = [];
        foreach ($this->fieldKeys() as $field) {
            $fields[$field] = 'edit';
        }

        $fields['code'] = 'locked';
        $fields['taxIdentificationNumber'] = 'locked';
        $fields['votersId'] = 'locked';
        $fields['socialSecurityNumber'] = 'locked';
        $fields['birthdate'] = 'locked';
        $fields['passportNumber'] = 'locked';
        $fields['paymentMethodId'] = 'hidden';

        return ['tabs' => $tabs, 'fields' => $fields];
    }

    /**
     * @return array{tabs: array<string, string>, fields: array<string, string>}
     */
    private function employeeAccess(): array
    {
        $tabs = [];
        foreach ($this->tabKeys() as $tab) {
            $tabs[$tab] = 'hidden';
        }
        $tabs['personal'] = 'view';
        $tabs['address'] = 'view';
        $tabs['contact'] = 'view';
        $tabs['documents'] = 'view';

        $fields = [];
        foreach ($this->fieldKeys() as $field) {
            $fields[$field] = 'view';
        }
        $fields['paymentMethodId'] = 'hidden';
        $fields['socialSecurityNumber'] = 'hidden';
        $fields['taxIdentificationNumber'] = 'hidden';
        $fields['votersId'] = 'hidden';
        $fields['passportNumber'] = 'hidden';

        return ['tabs' => $tabs, 'fields' => $fields];
    }

    /**
     * @return list<string>
     */
    private function tabKeys(): array
    {
        return [
            'personal',
            'address',
            'employment',
            'education',
            'payment_details',
            'contact',
            'contracts',
            'compensation',
            'documents',
            'incidents',
            'time_travel',
            'allowances',
            'deductions',
            'ss_benefit',
        ];
    }

    /**
     * @return list<string>
     */
    private function fieldKeys(): array
    {
        return [
            'code',
            'honorificId',
            'firstName',
            'lastName',
            'middleName',
            'maidenName',
            'birthdate',
            'socialSecurityNumber',
            'socialSecurityExpirationDate',
            'passportNumber',
            'votersId',
            'taxIdentificationNumber',
            'phone',
            'email',
            'genderId',
            'nationalityId',
            'citizenshipStatusId',
            'address1',
            'address2',
            'localityId',
            'unionMembership',
            'health',
            'employmentStatusId',
            'employeeStatusId',
            'timesheetTemplateId',
            'supervisorId',
            'paymentMethodId',
        ];
    }
};
