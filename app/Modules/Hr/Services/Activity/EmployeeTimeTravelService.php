<?php

namespace App\Modules\Hr\Services\Activity;

use App\Modules\Hr\Models\Employee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

class EmployeeTimeTravelService
{
    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function timeline(Employee $employee, array $filters = []): LengthAwarePaginator
    {
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 50)));
        $event = isset($filters['event']) ? strtolower((string) $filters['event']) : null;
        $resource = isset($filters['resource']) ? trim((string) $filters['resource']) : null;
        $search = isset($filters['search']) ? trim((string) $filters['search']) : null;

        $query = Activity::query()
            ->with(['causer:id,name,email'])
            ->where('log_name', config('employee-activity.log_name', 'employee'))
            ->where(function ($builder) use ($employee) {
                $builder
                    ->where(function ($q) use ($employee) {
                        $q->where('subject_type', $employee::class)
                            ->where('subject_id', $employee->id);
                    })
                    ->orWhere('properties->employee_id', $employee->id);
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (in_array($event, ['created', 'updated', 'deleted'], true)) {
            $query->where('event', $event);
        }

        if ($resource !== null && $resource !== '') {
            $query->where('properties->table', $resource);
        }

        if ($search !== null && $search !== '') {
            $like = '%'.Str::lower($search).'%';
            $query->where(function ($builder) use ($like) {
                $builder
                    ->whereRaw('LOWER(description) LIKE ?', [$like])
                    ->orWhereRaw("LOWER(COALESCE(properties->>'table', '')) LIKE ?", [$like])
                    ->orWhereRaw("LOWER(COALESCE(properties->>'resource', '')) LIKE ?", [$like]);
            });
        }

        return $query->paginate($perPage)->through(fn (Activity $activity) => $this->transform($activity));
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(Activity $activity): array
    {
        $properties = $activity->properties?->toArray() ?? [];
        $old = is_array($properties['old'] ?? null) ? $properties['old'] : [];
        $attributes = is_array($properties['attributes'] ?? null) ? $properties['attributes'] : [];

        $changes = [];
        if (($activity->event ?? '') === 'updated') {
            foreach ($attributes as $key => $newValue) {
                $changes[] = [
                    'field' => (string) $key,
                    'old' => $old[$key] ?? null,
                    'new' => $newValue,
                ];
            }
        } elseif (($activity->event ?? '') === 'created') {
            foreach ($attributes as $key => $newValue) {
                $changes[] = [
                    'field' => (string) $key,
                    'old' => null,
                    'new' => $newValue,
                ];
            }
        } elseif (($activity->event ?? '') === 'deleted') {
            foreach ($old as $key => $oldValue) {
                $changes[] = [
                    'field' => (string) $key,
                    'old' => $oldValue,
                    'new' => null,
                ];
            }
        }

        return [
            'id' => $activity->id,
            'event' => $activity->event,
            'description' => $activity->description,
            'resource' => $properties['resource'] ?? null,
            'table' => $properties['table'] ?? null,
            'recordId' => $properties['record_id'] ?? $activity->subject_id,
            'subjectType' => $activity->subject_type,
            'subjectId' => $activity->subject_id,
            'changes' => $changes,
            'occurredAt' => optional($activity->created_at)?->toIso8601String(),
            'causer' => $activity->causer ? [
                'id' => $activity->causer->id,
                'name' => $activity->causer->name ?? null,
                'email' => $activity->causer->email ?? null,
            ] : null,
        ];
    }
}
