<?php

namespace App\Modules\Hr\Observers;

use App\Modules\Hr\Services\Activity\EmployeeActivityResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class EmployeeActivityObserver
{
    public function __construct(
        private readonly EmployeeActivityResolver $resolver,
    ) {
    }

    public function created(Model $model): void
    {
        $this->write($model, 'created');
    }

    public function updated(Model $model): void
    {
        if ($model->wasChanged()) {
            $this->write($model, 'updated');
        }
    }

    public function deleted(Model $model): void
    {
        $this->write($model, 'deleted');
    }

    private function write(Model $model, string $event): void
    {
        if (! config('employee-activity.enabled', true)) {
            return;
        }

        $employeeIds = $this->resolver->resolveEmployeeIds($model);
        if ($employeeIds === []) {
            return;
        }

        $redact = config('employee-activity.redact_attributes', []);
        $attributes = $this->sanitize($model->getAttributes(), $redact);
        $old = [];
        $changes = [];

        if ($event === 'updated') {
            $changedKeys = array_keys($model->getChanges());
            $changes = $this->sanitize(Arr::only($model->getChanges(), $changedKeys), $redact);
            $old = $this->sanitize(Arr::only($model->getOriginal(), $changedKeys), $redact);
            if ($changes === []) {
                return;
            }
        } elseif ($event === 'deleted') {
            $old = $attributes;
            $attributes = [];
        }

        $resource = $this->resolver->resourceLabel($model);
        $description = match ($event) {
            'created' => "Created {$resource}",
            'updated' => "Updated {$resource}",
            'deleted' => "Deleted {$resource}",
            default => Str::title($event)." {$resource}",
        };

        foreach ($employeeIds as $employeeId) {
            \activity(config('employee-activity.log_name', 'employee'))
                ->causedBy(\auth()->user())
                ->performedOn($model)
                ->event($event)
                ->withProperties([
                    'employee_id' => $employeeId,
                    'table' => $model->getTable(),
                    'resource' => $resource,
                    'record_id' => (string) $model->getKey(),
                    'old' => $old,
                    'attributes' => $event === 'deleted' ? [] : ($event === 'updated' ? $changes : $attributes),
                ])
                ->log($description);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $redact
     * @return array<string, mixed>
     */
    private function sanitize(array $payload, array $redact): array
    {
        $clean = Arr::except($payload, [...$redact, 'created_at', 'updated_at']);

        foreach ($clean as $key => $value) {
            if (is_object($value) && ! ($value instanceof \Stringable)) {
                unset($clean[$key]);
                continue;
            }
            if (is_resource($value)) {
                unset($clean[$key]);
            }
        }

        return $clean;
    }
}
