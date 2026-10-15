<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Models\SchedulerDailyMetric;
use App\Models\SchedulerMetricDefinition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SchedulerDailyMetricController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after_or_equal:start'],
        ]);

        $definitions = SchedulerMetricDefinition::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $byDate = [];
        if ($definitions->isNotEmpty()) {
            $values = SchedulerDailyMetric::query()
                ->whereIn('scheduler_metric_definition_id', $definitions->pluck('id'))
                ->whereBetween('date', [$validated['start'], $validated['end']])
                ->get();

            foreach ($values as $row) {
                $dateKey = $row->date?->format('Y-m-d') ?? (string) $row->getRawOriginal('date');
                if (! isset($byDate[$dateKey])) {
                    $byDate[$dateKey] = [];
                }
                $byDate[$dateKey][(string) $row->scheduler_metric_definition_id] = $row->value !== null
                    ? (float) $row->value
                    : null;
            }
        }

        return response()->json([
            'definitions' => $definitions,
            'values_by_date' => $byDate,
        ]);
    }

    public function upsert(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date'],
            'values' => ['required', 'array'],
            'values.*' => ['nullable', 'numeric'],
        ]);

        $definitionIds = array_map('intval', array_keys($validated['values']));
        $activeDefinitions = SchedulerMetricDefinition::query()
            ->where('is_active', true)
            ->whereIn('id', $definitionIds)
            ->get()
            ->keyBy('id');

        if ($activeDefinitions->count() !== count($definitionIds)) {
            throw ValidationException::withMessages([
                'values' => ['One or more metrics are inactive or unknown.'],
            ]);
        }

        $userId = $request->user()?->id;
        $date = $validated['date'];

        DB::transaction(function () use ($validated, $activeDefinitions, $date, $userId) {
            foreach ($validated['values'] as $definitionId => $rawValue) {
                $definition = $activeDefinitions->get((int) $definitionId);
                if (! $definition) {
                    continue;
                }

                $value = $rawValue === null || $rawValue === ''
                    ? null
                    : $this->normalizeValue($definition, $rawValue);

                if ($value === null) {
                    SchedulerDailyMetric::query()
                        ->where('scheduler_metric_definition_id', $definition->id)
                        ->whereDate('date', $date)
                        ->delete();

                    continue;
                }

                SchedulerDailyMetric::query()->updateOrCreate(
                    [
                        'scheduler_metric_definition_id' => $definition->id,
                        'date' => $date,
                    ],
                    [
                        'value' => $value,
                        'updated_by_id' => $userId,
                    ],
                );
            }
        });

        $fresh = SchedulerDailyMetric::query()
            ->whereIn('scheduler_metric_definition_id', $activeDefinitions->keys())
            ->whereDate('date', $date)
            ->get()
            ->mapWithKeys(fn (SchedulerDailyMetric $row) => [
                (string) $row->scheduler_metric_definition_id => $row->value !== null
                    ? (float) $row->value
                    : null,
            ]);

        return response()->json([
            'message' => 'Daily metrics saved successfully',
            'date' => $date,
            'values' => $fresh,
        ]);
    }

    private function normalizeValue(SchedulerMetricDefinition $definition, mixed $rawValue): ?float
    {
        if ($rawValue === null || $rawValue === '') {
            return null;
        }

        $number = (float) $rawValue;
        if ($definition->value_type === SchedulerMetricDefinition::VALUE_TYPE_INTEGER) {
            return (float) (int) round($number);
        }

        return round($number, 2);
    }
}
