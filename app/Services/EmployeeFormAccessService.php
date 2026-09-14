<?php

namespace App\Services;

use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Arr;

class EmployeeFormAccessService
{
    public const TAB_MODES = ['hidden', 'view', 'edit'];

    public const FIELD_MODES = ['hidden', 'view', 'locked', 'edit'];

    private const TAB_RANK = [
        'hidden' => 0,
        'view' => 1,
        'edit' => 2,
    ];

    private const FIELD_RANK = [
        'hidden' => 0,
        'view' => 1,
        'locked' => 2,
        'edit' => 3,
    ];

    /**
     * @return list<array{key: string, label: string, group: string}>
     */
    public function tabCatalog(): array
    {
        return [
            ['key' => 'personal', 'label' => 'Personal Info', 'group' => 'stepper'],
            ['key' => 'address', 'label' => 'Address', 'group' => 'stepper'],
            ['key' => 'employment', 'label' => 'Employment', 'group' => 'stepper'],
            ['key' => 'education', 'label' => 'Education', 'group' => 'stepper'],
            ['key' => 'payment_details', 'label' => 'Payment Details', 'group' => 'stepper'],
            ['key' => 'contact', 'label' => 'Contact Info', 'group' => 'stepper'],
            ['key' => 'contracts', 'label' => 'Contracts', 'group' => 'details'],
            ['key' => 'compensation', 'label' => 'Compensation', 'group' => 'details'],
            ['key' => 'documents', 'label' => 'Documents', 'group' => 'details'],
            ['key' => 'incidents', 'label' => 'Incidents', 'group' => 'details'],
            ['key' => 'time_travel', 'label' => 'Time Travel', 'group' => 'details'],
            ['key' => 'allowances', 'label' => 'Default Other Payments', 'group' => 'details'],
            ['key' => 'deductions', 'label' => 'Default Deductions', 'group' => 'details'],
            ['key' => 'ss_benefit', 'label' => 'SS Benefit', 'group' => 'details'],
        ];
    }

    /**
     * @return list<array{key: string, label: string, tab: string}>
     */
    public function fieldCatalog(): array
    {
        return [
            ['key' => 'code', 'label' => 'Employee Code', 'tab' => 'personal'],
            ['key' => 'honorificId', 'label' => 'Honorific', 'tab' => 'personal'],
            ['key' => 'firstName', 'label' => 'First Name', 'tab' => 'personal'],
            ['key' => 'lastName', 'label' => 'Last Name', 'tab' => 'personal'],
            ['key' => 'middleName', 'label' => 'Middle Name', 'tab' => 'personal'],
            ['key' => 'maidenName', 'label' => 'Maiden Name', 'tab' => 'personal'],
            ['key' => 'birthdate', 'label' => 'Birthdate', 'tab' => 'personal'],
            ['key' => 'socialSecurityNumber', 'label' => 'Social Security Number', 'tab' => 'personal'],
            ['key' => 'socialSecurityExpirationDate', 'label' => 'SS Expiration Date', 'tab' => 'personal'],
            ['key' => 'passportNumber', 'label' => 'Passport Number', 'tab' => 'personal'],
            ['key' => 'votersId', 'label' => 'Voters ID', 'tab' => 'personal'],
            ['key' => 'taxIdentificationNumber', 'label' => 'Tax Identification Number', 'tab' => 'personal'],
            ['key' => 'phone', 'label' => 'Phone', 'tab' => 'personal'],
            ['key' => 'email', 'label' => 'Email', 'tab' => 'personal'],
            ['key' => 'genderId', 'label' => 'Gender', 'tab' => 'personal'],
            ['key' => 'nationalityId', 'label' => 'Nationality', 'tab' => 'personal'],
            ['key' => 'citizenshipStatusId', 'label' => 'Citizenship Status', 'tab' => 'personal'],
            ['key' => 'address1', 'label' => 'Address 1', 'tab' => 'address'],
            ['key' => 'address2', 'label' => 'Address 2', 'tab' => 'address'],
            ['key' => 'localityId', 'label' => 'Locality', 'tab' => 'address'],
            ['key' => 'unionMembership', 'label' => 'Union Membership', 'tab' => 'address'],
            ['key' => 'health', 'label' => 'Health', 'tab' => 'address'],
            ['key' => 'employmentStatusId', 'label' => 'Employment Status', 'tab' => 'employment'],
            ['key' => 'employeeStatusId', 'label' => 'Employee Status', 'tab' => 'employment'],
            ['key' => 'timesheetTemplateId', 'label' => 'Timesheet Template', 'tab' => 'employment'],
            ['key' => 'supervisorId', 'label' => 'Supervisor', 'tab' => 'employment'],
            ['key' => 'paymentMethodId', 'label' => 'Payment Method', 'tab' => 'employment'],
        ];
    }

    /**
     * @return array{tabs: array<string, string>, fields: array<string, string>}
     */
    public function fullAccess(): array
    {
        return $this->normalize(null, preferFull: true);
    }

    /**
     * @return array{tabs: array<string, string>, fields: array<string, string>}
     */
    public function hrAccess(): array
    {
        $profile = $this->fullAccess();

        $profile['tabs']['payment_details'] = 'hidden';
        $profile['tabs']['compensation'] = 'hidden';
        $profile['tabs']['time_travel'] = 'hidden';
        $profile['tabs']['allowances'] = 'hidden';
        $profile['tabs']['deductions'] = 'hidden';
        $profile['tabs']['ss_benefit'] = 'hidden';
        $profile['tabs']['documents'] = 'view';

        $profile['fields']['code'] = 'locked';
        $profile['fields']['taxIdentificationNumber'] = 'locked';
        $profile['fields']['votersId'] = 'locked';
        $profile['fields']['socialSecurityNumber'] = 'locked';
        $profile['fields']['birthdate'] = 'locked';
        $profile['fields']['passportNumber'] = 'locked';
        $profile['fields']['paymentMethodId'] = 'hidden';

        return $profile;
    }

    /**
     * @return array{tabs: array<string, string>, fields: array<string, string>}
     */
    public function defaultForRoleName(string $roleName): array
    {
        return match (strtolower($roleName)) {
            'admin', 'gm', 'accountant', 'payroll-accountant' => $this->fullAccess(),
            'hr', 'supervisor' => $this->hrAccess(),
            default => $this->normalize(null),
        };
    }

    /**
     * @param  array<string, mixed>|null  $profile
     * @return array{tabs: array<string, string>, fields: array<string, string>}
     */
    public function normalize(?array $profile, bool $preferFull = false): array
    {
        $defaultTab = $preferFull ? 'edit' : 'hidden';
        $defaultField = $preferFull ? 'edit' : 'hidden';

        $tabs = [];
        foreach ($this->tabCatalog() as $tab) {
            $mode = Arr::get($profile, 'tabs.'.$tab['key'], $defaultTab);
            $tabs[$tab['key']] = $this->normalizeTabMode(is_string($mode) ? $mode : $defaultTab);
        }

        $fields = [];
        foreach ($this->fieldCatalog() as $field) {
            $mode = Arr::get($profile, 'fields.'.$field['key'], $defaultField);
            $fields[$field['key']] = $this->normalizeFieldMode(is_string($mode) ? $mode : $defaultField);
        }

        return ['tabs' => $tabs, 'fields' => $fields];
    }

    /**
     * @return array{tabs: array<string, string>, fields: array<string, string>}
     */
    public function profileForRole(Role $role): array
    {
        $stored = $role->employee_form_access;
        if (is_array($stored) && $stored !== []) {
            return $this->normalize($stored);
        }

        return $this->defaultForRoleName($role->name);
    }

    /**
     * Most permissive merge across the user's roles.
     *
     * @return array{tabs: array<string, string>, fields: array<string, string>}
     */
    public function forUser(?User $user): array
    {
        if (! $user) {
            return $this->normalize(null);
        }

        $roles = Role::query()
            ->whereIn('name', $user->getRoleNames()->all())
            ->get();

        if ($roles->isEmpty()) {
            return $this->normalize(null);
        }

        $merged = null;
        foreach ($roles as $role) {
            $profile = $this->profileForRole($role);
            $merged = $merged === null ? $profile : $this->mergeProfiles($merged, $profile);
        }

        return $merged ?? $this->normalize(null);
    }

    /**
     * @param  array{tabs: array<string, string>, fields: array<string, string>}  $a
     * @param  array{tabs: array<string, string>, fields: array<string, string>}  $b
     * @return array{tabs: array<string, string>, fields: array<string, string>}
     */
    public function mergeProfiles(array $a, array $b): array
    {
        $tabs = [];
        foreach ($this->tabCatalog() as $tab) {
            $key = $tab['key'];
            $tabs[$key] = $this->maxTabMode($a['tabs'][$key] ?? 'hidden', $b['tabs'][$key] ?? 'hidden');
        }

        $fields = [];
        foreach ($this->fieldCatalog() as $field) {
            $key = $field['key'];
            $fields[$key] = $this->maxFieldMode($a['fields'][$key] ?? 'hidden', $b['fields'][$key] ?? 'hidden');
        }

        return ['tabs' => $tabs, 'fields' => $fields];
    }

    public function tabVisible(array $profile, string $tab): bool
    {
        return ($profile['tabs'][$tab] ?? 'hidden') !== 'hidden';
    }

    public function tabEditable(array $profile, string $tab): bool
    {
        return ($profile['tabs'][$tab] ?? 'hidden') === 'edit';
    }

    public function fieldVisible(array $profile, string $field): bool
    {
        return ($profile['fields'][$field] ?? 'hidden') !== 'hidden';
    }

    public function fieldWritable(array $profile, string $field, bool $isCreate): bool
    {
        $mode = $profile['fields'][$field] ?? 'hidden';

        return match ($mode) {
            'edit' => true,
            'locked' => $isCreate,
            default => false,
        };
    }

    /**
     * Strip attributes the user cannot write; fill defaults when needed.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array{tabs: array<string, string>, fields: array<string, string>}  $profile
     * @return array<string, mixed>
     */
    public function filterWritableAttributes(array $attributes, array $profile, bool $isCreate): array
    {
        $filtered = [];

        foreach ($attributes as $key => $value) {
            if (! array_key_exists($key, $profile['fields'])) {
                $filtered[$key] = $value;
                continue;
            }

            if ($this->fieldWritable($profile, $key, $isCreate)) {
                $filtered[$key] = $value;
            }
        }

        if ($isCreate && ! $this->fieldWritable($profile, 'paymentMethodId', true)) {
            $defaultPaymentMethodId = PaymentMethod::query()->orderBy('id')->value('id');
            if ($defaultPaymentMethodId !== null) {
                $filtered['paymentMethodId'] = $defaultPaymentMethodId;
            }
        }

        return $filtered;
    }

    /**
     * Hide field values / nested resources the user cannot see.
     *
     * @param  array<string, mixed>  $payload
     * @param  array{tabs: array<string, string>, fields: array<string, string>}  $profile
     * @return array<string, mixed>
     */
    public function sanitizeReadablePayload(array $payload, array $profile): array
    {
        foreach ($profile['fields'] as $field => $mode) {
            if ($mode !== 'hidden') {
                continue;
            }

            if (array_key_exists($field, $payload)) {
                $payload[$field] = null;
            }

            if (isset($payload['person']) && is_array($payload['person']) && array_key_exists($field, $payload['person'])) {
                $payload['person'][$field] = null;
            }
        }

        $tabPayloadKeys = [
            'payment_details' => ['employeeBanks', 'employee_banks', 'paymentMethod', 'payment_method'],
            'compensation' => ['employeeCompensations', 'employee_compensations'],
            'allowances' => ['allowances'],
            'deductions' => ['employeeDefaultDeductions', 'employee_default_deductions'],
            'contact' => ['contacts'],
            'education' => ['qualifications'],
            'contracts' => ['employmentDetails', 'employment_details'],
        ];

        foreach ($tabPayloadKeys as $tab => $keys) {
            if ($this->tabVisible($profile, $tab)) {
                continue;
            }

            foreach ($keys as $key) {
                if (array_key_exists($key, $payload)) {
                    $payload[$key] = null;
                }
            }
        }

        if (! $this->fieldVisible($profile, 'paymentMethodId')) {
            $payload['paymentMethodId'] = null;
            $payload['paymentMethod'] = null;
            $payload['payment_method'] = null;
        }

        return $payload;
    }

    /**
     * Relations needed for employee detail based on role tab access.
     *
     * @param  array{tabs: array<string, string>, fields: array<string, string>}  $profile
     * @return list<string>
     */
    public function detailRelationsForProfile(array $profile): array
    {
        $relations = [
            'user',
            'person.locality',
            'person.honorific',
            'person.gender',
            'person.citizenshipStatus',
            'person.nationality',
            'employmentStatus',
            'employeeStatus',
            'timesheetTemplate',
            'payrateFrequency',
            'supervisor.person',
        ];

        if ($this->fieldVisible($profile, 'paymentMethodId') || $this->tabVisible($profile, 'payment_details')) {
            $relations[] = 'paymentMethod';
        }

        if ($this->tabVisible($profile, 'contracts') || $this->tabVisible($profile, 'employment')) {
            array_push(
                $relations,
                'employmentDetails.department',
                'employmentDetails.worksite',
                'employmentDetails.contractType',
                'employmentDetails.jobTitle',
                'employmentDetails.defaultPayPeriodGroup',
            );
        }

        if ($this->tabVisible($profile, 'compensation')) {
            $relations[] = 'employeeCompensations';
        }

        if ($this->tabVisible($profile, 'allowances')) {
            $relations[] = 'allowances';
        }

        if ($this->tabVisible($profile, 'payment_details')) {
            $relations[] = 'employeeBanks';
        }

        if ($this->tabVisible($profile, 'contact')) {
            $relations[] = 'contacts';
        }

        if ($this->tabVisible($profile, 'deductions')) {
            $relations[] = 'employeeDefaultDeductions';
        }

        if ($this->tabVisible($profile, 'education')) {
            $relations[] = 'qualifications';
        }

        return array_values(array_unique($relations));
    }

    private function normalizeTabMode(string $mode): string
    {
        $mode = strtolower($mode);

        return in_array($mode, self::TAB_MODES, true) ? $mode : 'hidden';
    }

    private function normalizeFieldMode(string $mode): string
    {
        $mode = strtolower($mode);

        return in_array($mode, self::FIELD_MODES, true) ? $mode : 'hidden';
    }

    private function maxTabMode(string $a, string $b): string
    {
        return (self::TAB_RANK[$a] ?? 0) >= (self::TAB_RANK[$b] ?? 0) ? $a : $b;
    }

    private function maxFieldMode(string $a, string $b): string
    {
        return (self::FIELD_RANK[$a] ?? 0) >= (self::FIELD_RANK[$b] ?? 0) ? $a : $b;
    }
}
