<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Models\SchedulerMetricDefinition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class SchedulerMetricDefinitionController extends Controller
{
    private const VALUE_TYPES = [
        SchedulerMetricDefinition::VALUE_TYPE_INTEGER,
        SchedulerMetricDefinition::VALUE_TYPE_DECIMAL,
    ];

    public function index(Request $request): JsonResponse
    {
        $query = SchedulerMetricDefinition::query()->orderBy('sort_order')->orderBy('name');

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->string('search'));
            $query->where(function ($inner) use ($search) {
                $inner->where('name', 'ilike', "%{$search}%")
                    ->orWhere('code', 'ilike', "%{$search}%")
                    ->orWhere('short_label', 'ilike', "%{$search}%");
            });
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

        $definition = SchedulerMetricDefinition::create($this->normalize($validated));

        return response()->json([
            'message' => 'Scheduler metric created successfully',
            'data' => $definition,
        ], Response::HTTP_CREATED);
    }

    public function show(SchedulerMetricDefinition $schedulerMetricDefinition): JsonResponse
    {
        return response()->json($schedulerMetricDefinition);
    }

    public function update(Request $request, SchedulerMetricDefinition $schedulerMetricDefinition): JsonResponse
    {
        $validated = $request->validate($this->rules($schedulerMetricDefinition->id));
        $schedulerMetricDefinition->update($this->normalize($validated));

        return response()->json([
            'message' => 'Scheduler metric updated successfully',
            'data' => $schedulerMetricDefinition->fresh(),
        ]);
    }

    public function destroy(SchedulerMetricDefinition $schedulerMetricDefinition): JsonResponse
    {
        $schedulerMetricDefinition->delete();

        return response()->json(['message' => 'Scheduler metric deleted successfully']);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?int $ignoreId = null): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('scheduler_metric_definition', 'code')->ignore($ignoreId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'short_label' => ['nullable', 'string', 'max:32'],
            'value_type' => ['required', Rule::in(self::VALUE_TYPES)],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalize(array $validated): array
    {
        return [
            'code' => strtolower(trim((string) $validated['code'])),
            'name' => trim((string) $validated['name']),
            'short_label' => isset($validated['short_label']) && $validated['short_label'] !== ''
                ? trim((string) $validated['short_label'])
                : null,
            'value_type' => $validated['value_type'],
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ];
    }
}
