<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Models\FormAccessField;
use App\Modules\Core\Models\FormAccessForm;
use App\Modules\Core\Models\FormAccessTab;
use App\Modules\Core\Services\FormAccessCatalog;
use App\Services\EmployeeFormAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FormAccessCatalogController extends Controller
{
    public function __construct(
        private readonly FormAccessCatalog $catalog,
        private readonly EmployeeFormAccessService $employeeFormAccess,
    ) {
    }

    public function showEmployee(Request $request): JsonResponse
    {
        $includeInactive = $request->boolean('include_inactive');

        return response()->json([
            'form' => FormAccessForm::KEY_EMPLOYEE,
            'tabs' => $this->catalog->tabs(),
            'fields' => $includeInactive ? $this->catalog->allFields() : $this->catalog->fields(),
            'input_types' => FormAccessField::INPUT_TYPES,
            'tab_modes' => EmployeeFormAccessService::TAB_MODES,
            'field_modes' => EmployeeFormAccessService::FIELD_MODES,
        ]);
    }

    public function storeEmployeeField(Request $request): JsonResponse
    {
        $form = $this->employeeForm();
        $validated = $this->validateField($request, $form);

        $maxSort = (int) FormAccessField::query()->where('form_id', $form->id)->max('sort');
        $field = FormAccessField::query()->create([
            'form_id' => $form->id,
            'tab_key' => $validated['tab_key'],
            'key' => $validated['key'],
            'label' => $validated['label'],
            'input_type' => $validated['input_type'],
            'is_required' => $validated['is_required'],
            'options' => $validated['options'],
            'help_text' => $validated['help_text'],
            'sort' => $maxSort + 1,
            'is_system' => false,
            'is_active' => $validated['is_active'],
        ]);

        $this->catalog->forget($form->key);

        return response()->json([
            'message' => 'Employee field created.',
            'data' => $this->catalog->presentField($field->fresh()),
        ], Response::HTTP_CREATED);
    }

    public function updateEmployeeField(Request $request, FormAccessField $formAccessField): JsonResponse
    {
        $form = $this->employeeForm();
        if ((string) $formAccessField->form_id !== (string) $form->id) {
            return response()->json(['message' => 'Field not found.'], 404);
        }

        if ($formAccessField->is_system) {
            return response()->json(['message' => 'Built-in employee fields cannot be changed.'], 422);
        }

        $validated = $this->validateField($request, $form, $formAccessField);

        $formAccessField->update([
            'tab_key' => $validated['tab_key'],
            'key' => $validated['key'],
            'label' => $validated['label'],
            'input_type' => $validated['input_type'],
            'is_required' => $validated['is_required'],
            'options' => $validated['options'],
            'help_text' => $validated['help_text'],
            'is_active' => $validated['is_active'],
        ]);

        $this->catalog->forget($form->key);

        return response()->json([
            'message' => 'Employee field saved.',
            'data' => $this->catalog->presentField($formAccessField->fresh()),
        ]);
    }

    public function destroyEmployeeField(FormAccessField $formAccessField): JsonResponse
    {
        $form = $this->employeeForm();
        if ((string) $formAccessField->form_id !== (string) $form->id) {
            return response()->json(['message' => 'Field not found.'], 404);
        }
        if ($formAccessField->is_system) {
            return response()->json(['message' => 'Built-in employee fields cannot be deleted.'], 422);
        }

        $formAccessField->delete();
        $this->catalog->forget($form->key);

        return response()->json(['message' => 'Employee field deleted.']);
    }

    private function employeeForm(): FormAccessForm
    {
        $form = FormAccessForm::query()->where('key', FormAccessForm::KEY_EMPLOYEE)->first();
        if (! $form) {
            $form = $this->catalog->syncEmployee();
        }

        return $form;
    }

    /**
     * @return array{
     *     tab_key: string,
     *     key: string,
     *     label: string,
     *     input_type: string,
     *     is_required: bool,
     *     options: list<array{label: string, value: string}>,
     *     help_text: ?string,
     *     is_active: bool
     * }
     */
    private function validateField(Request $request, FormAccessForm $form, ?FormAccessField $existing = null): array
    {
        $tabKeys = FormAccessTab::query()->where('form_id', $form->id)->pluck('key')->all();
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:128'],
            'tab_key' => ['required', 'string', Rule::in($tabKeys)],
            'key' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z][A-Za-z0-9]*$/'],
            'input_type' => ['nullable', 'string', Rule::in(FormAccessField::INPUT_TYPES)],
            'is_required' => ['sometimes', 'boolean'],
            'options' => ['nullable', 'array'],
            'options.*.label' => ['nullable', 'string', 'max:128'],
            'options.*.value' => ['nullable', 'string', 'max:128'],
            'help_text' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $label = trim((string) $validated['label']);
        $key = trim((string) ($validated['key'] ?? ''));
        if ($key === '') {
            $key = $this->catalog->makeFieldKey($label, $existing?->key);
        } elseif (in_array($key, $this->catalog->reservedFieldKeys(), true) && $key !== $existing?->key) {
            throw ValidationException::withMessages([
                'key' => 'That field key is reserved.',
            ]);
        } elseif (
            FormAccessField::query()
                ->where('form_id', $form->id)
                ->where('key', $key)
                ->when($existing, fn ($query) => $query->where('id', '!=', $existing->id))
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'key' => 'That field key is already in use.',
            ]);
        }

        $options = [];
        foreach ($validated['options'] ?? [] as $option) {
            if (! is_array($option)) {
                continue;
            }
            $value = trim((string) ($option['value'] ?? $option['label'] ?? ''));
            $optionLabel = trim((string) ($option['label'] ?? $value));
            if ($value === '') {
                continue;
            }
            $options[] = ['label' => $optionLabel !== '' ? $optionLabel : $value, 'value' => $value];
        }

        $inputType = $validated['input_type'] ?? 'text';
        if ($inputType !== 'select') {
            $options = [];
        }

        return [
            'tab_key' => $validated['tab_key'],
            'key' => $key,
            'label' => $label,
            'input_type' => $inputType,
            'is_required' => (bool) ($validated['is_required'] ?? false),
            'options' => $options,
            'help_text' => filled($validated['help_text'] ?? null) ? trim((string) $validated['help_text']) : null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ];
    }
}
