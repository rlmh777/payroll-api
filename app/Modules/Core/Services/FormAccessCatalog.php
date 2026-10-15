<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\FormAccessField;
use App\Modules\Core\Models\FormAccessForm;
use App\Modules\Core\Models\FormAccessTab;

class FormAccessCatalog
{
    /** @var array<string, list<array{key: string, label: string, group: string}>> */
    private array $tabsByForm = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $fieldsByForm = [];

    /**
     * @return list<array{key: string, label: string, group: string}>
     */
    public function tabs(string $formKey = FormAccessForm::KEY_EMPLOYEE): array
    {
        return $this->tabsByForm[$formKey] ??= $this->loadTabs($formKey);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fields(string $formKey = FormAccessForm::KEY_EMPLOYEE): array
    {
        return $this->fieldsByForm[$formKey] ??= $this->loadFields($formKey, true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function allFields(string $formKey = FormAccessForm::KEY_EMPLOYEE): array
    {
        return $this->loadFields($formKey, false);
    }

    /**
     * @return list<string>
     */
    public function customFieldKeys(string $formKey = FormAccessForm::KEY_EMPLOYEE): array
    {
        $keys = [];
        foreach ($this->fields($formKey) as $field) {
            if (($field['is_system'] ?? true) === false) {
                $keys[] = (string) $field['key'];
            }
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    public function reservedFieldKeys(): array
    {
        $keys = [
            'id',
            'person',
            'personId',
            'person_id',
            'user',
            'user_id',
            'customAttributes',
            'custom_attributes',
        ];
        foreach ($this->employeeFieldDefinitions() as $field) {
            $keys[] = $field['key'];
        }

        return array_values(array_unique($keys));
    }

    public function syncEmployee(): FormAccessForm
    {
        $form = FormAccessForm::query()->updateOrCreate(
            ['key' => FormAccessForm::KEY_EMPLOYEE],
            ['name' => 'Employee'],
        );

        foreach ($this->employeeTabDefinitions() as $index => $tab) {
            FormAccessTab::query()->updateOrCreate(
                ['form_id' => $form->id, 'key' => $tab['key']],
                [
                    'label' => $tab['label'],
                    'group' => $tab['group'],
                    'sort' => $index,
                    'is_system' => true,
                    'is_active' => true,
                ],
            );
        }

        foreach ($this->employeeFieldDefinitions() as $index => $field) {
            FormAccessField::query()->updateOrCreate(
                ['form_id' => $form->id, 'key' => $field['key']],
                [
                    'tab_key' => $field['tab'],
                    'label' => $field['label'],
                    'sort' => $index,
                    'input_type' => 'text',
                    'is_required' => false,
                    'is_system' => true,
                    'is_active' => true,
                ],
            );
        }

        $this->forget($form->key);

        return $form;
    }

    public function makeFieldKey(string $label, ?string $existingKey = null): string
    {
        $base = lcfirst(str_replace(' ', '', ucwords(strtolower(trim($label)))));
        $base = preg_replace('/[^a-zA-Z0-9]/', '', $base) ?: 'customField';
        if ($base !== '' && ! ctype_alpha($base[0])) {
            $base = 'custom'.$base;
        }

        $reserved = array_fill_keys($this->reservedFieldKeys(), true);
        if ($existingKey) {
            unset($reserved[$existingKey]);
        }

        $candidate = $base;
        $suffix = 2;
        $taken = FormAccessField::query()
            ->whereHas('form', fn ($query) => $query->where('key', FormAccessForm::KEY_EMPLOYEE))
            ->when($existingKey, fn ($query) => $query->where('key', '!=', $existingKey))
            ->pluck('key')
            ->all();
        $taken = array_fill_keys($taken, true) + $reserved;

        while (isset($taken[$candidate])) {
            $candidate = $base.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    public function forget(?string $formKey = null): void
    {
        if ($formKey === null) {
            $this->tabsByForm = [];
            $this->fieldsByForm = [];

            return;
        }

        unset($this->tabsByForm[$formKey], $this->fieldsByForm[$formKey]);
    }

    /**
     * @return list<array{key: string, label: string, group: string}>
     */
    public function employeeTabDefinitions(): array
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
    public function employeeFieldDefinitions(): array
    {
        return [
            ['key' => 'code', 'label' => 'Employee Code', 'tab' => 'personal'],
            ['key' => 'internalId1', 'label' => 'Internal Code 1', 'tab' => 'personal'],
            ['key' => 'internalId2', 'label' => 'Internal Code 2', 'tab' => 'personal'],
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
     * @return list<array{key: string, label: string, group: string}>
     */
    private function loadTabs(string $formKey): array
    {
        $form = FormAccessForm::query()->where('key', $formKey)->first();
        if (! $form) {
            return [];
        }

        return $form->tabs()
            ->where('is_active', true)
            ->get(['key', 'label', 'group'])
            ->map(fn (FormAccessTab $tab) => [
                'key' => $tab->key,
                'label' => $tab->label,
                'group' => $tab->group,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadFields(string $formKey, bool $activeOnly = true): array
    {
        $form = FormAccessForm::query()->where('key', $formKey)->first();
        if (! $form) {
            return [];
        }

        $query = $form->fields();
        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $query
            ->get()
            ->map(fn (FormAccessField $field) => $this->presentField($field))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function presentField(FormAccessField $field): array
    {
        $inputType = (string) ($field->input_type ?: 'text');
        if (! in_array($inputType, FormAccessField::INPUT_TYPES, true)) {
            $inputType = 'text';
        }

        $options = [];
        foreach ($field->options ?? [] as $option) {
            if (is_string($option) && $option !== '') {
                $options[] = ['label' => $option, 'value' => $option];
                continue;
            }
            if (! is_array($option)) {
                continue;
            }
            $value = trim((string) ($option['value'] ?? $option['label'] ?? ''));
            $label = trim((string) ($option['label'] ?? $value));
            if ($value === '') {
                continue;
            }
            $options[] = ['label' => $label !== '' ? $label : $value, 'value' => $value];
        }

        return [
            'id' => $field->id,
            'key' => $field->key,
            'label' => $field->label,
            'tab' => $field->tab_key,
            'input_type' => $inputType,
            'is_required' => (bool) $field->is_required,
            'options' => $options,
            'help_text' => $field->help_text,
            'is_system' => (bool) $field->is_system,
            'is_active' => (bool) $field->is_active,
            'sort' => (int) $field->sort,
        ];
    }
}
