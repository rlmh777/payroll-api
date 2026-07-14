<?php

namespace App\Modules\Hr\Services\Scheduling;

use App\Models\CalendarGroup;
use App\Models\ScheduleEmployeeTimesheet;
use App\Modules\Hr\Services\Attendance\ScheduledWorkOverlapValidator;
use App\Modules\Hr\Services\Employment\EmploymentContractAssignmentService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmployeeScheduleService
{
  public function __construct(
    private readonly EmploymentContractAssignmentService $contractAssignmentService,
    private readonly ScheduledWorkOverlapValidator $overlapValidator,
  ) {
  }

  private const DAY_ISO = [
    'Mon' => 1,
    'Tue' => 2,
    'Wed' => 3,
    'Thu' => 4,
    'Fri' => 5,
    'Sat' => 6,
    'Sun' => 7,
  ];

  /**
   * @param  array{
   *   employeeId: string,
   *   employmentDetailId?: string|null,
   *   departmentId?: int|null,
   *   date?: string,
   *   startDate?: string,
   *   endDate?: string,
   *   days?: array<int, string>,
   *   startTime: string,
   *   endTime: string,
   *   include_lunch_hour?: bool,
   *   mode: 'single'|'series',
   * }  $payload
   * @return array{records: Collection<int, ScheduleEmployeeTimesheet>, series_id: string|null}
   */
  public function create(array $payload): array
  {
    $scheduleGroup = CalendarGroup::where('key', 'schedules')->first();
    $startDate = ($payload['mode'] ?? 'single') === 'series'
      ? (string) ($payload['startDate'] ?? '')
      : (string) ($payload['date'] ?? '');
    $endDate = ($payload['mode'] ?? 'single') === 'series'
      ? (string) ($payload['endDate'] ?? $startDate)
      : $startDate;
    $employmentDetailId = $this->contractAssignmentService->resolveContractId(
      $payload['employeeId'],
      $payload['employmentDetailId'] ?? null,
      isset($payload['departmentId']) ? (int) $payload['departmentId'] : null,
      $startDate,
      $endDate,
    );
    $base = [
      'employeeId' => $payload['employeeId'],
      'employmentDetailId' => $employmentDetailId,
      'departmentId' => $payload['departmentId'] ?? null,
      'startTime' => $payload['startTime'],
      'endTime' => $payload['endTime'],
      'include_lunch_hour' => (bool) ($payload['include_lunch_hour'] ?? false),
      'lunch_hour_hours' => $payload['lunch_hour_hours'] ?? 1,
      'approvalStatus' => 'pending',
      'calendar_group_id' => $scheduleGroup?->id,
    ];

    if (($payload['mode'] ?? 'single') === 'series') {
      $seriesId = (string) Str::uuid();
      $dates = $this->resolveSeriesDates(
        $payload['startDate'] ?? '',
        $payload['endDate'] ?? '',
        $payload['days'] ?? [],
      );

      foreach ($dates as $date) {
        $this->overlapValidator->validate(
          $payload['employeeId'],
          $date,
          $date,
          $payload['startTime'],
          $payload['endTime'],
        );
      }

      $records = $dates->map(function (string $date) use ($base, $seriesId) {
        return ScheduleEmployeeTimesheet::create(array_merge($base, [
          'id' => (string) Str::uuid(),
          'date' => $date,
          'series_id' => $seriesId,
        ]));
      });

      return ['records' => $records, 'series_id' => $seriesId];
    }

    $this->overlapValidator->validate(
      $payload['employeeId'],
      (string) $payload['date'],
      (string) $payload['date'],
      $payload['startTime'],
      $payload['endTime'],
    );

    $record = ScheduleEmployeeTimesheet::create(array_merge($base, [
      'id' => (string) Str::uuid(),
      'date' => $payload['date'],
      'series_id' => null,
    ]));

    return ['records' => collect([$record]), 'series_id' => null];
  }

  /**
   * @param  array{
   *   scope: 'single'|'series',
   *   employmentDetailId?: string|null,
   *   departmentId?: int|null,
   *   startTime?: string,
   *   endTime?: string,
   *   include_lunch_hour?: bool,
   *   startDate?: string,
   *   endDate?: string,
   *   days?: array<int, string>,
   * }  $payload
   */
  public function update(ScheduleEmployeeTimesheet $schedule, array $payload): Collection
  {
    $scope = $payload['scope'] ?? 'single';
    $targets = $scope === 'series' && $schedule->series_id
      ? ScheduleEmployeeTimesheet::query()->where('series_id', $schedule->series_id)->get()
      : collect([$schedule]);

    if ($scope === 'series' && $schedule->series_id && $this->hasSeriesDateChanges($payload)) {
      return $this->regenerateSeries($schedule, $payload);
    }

    $fields = [];
    if (array_key_exists('departmentId', $payload)) {
        $fields['departmentId'] = $payload['departmentId'];
    }
    if (array_key_exists('employmentDetailId', $payload)) {
        $fields['employmentDetailId'] = $payload['employmentDetailId'];
    }
    if (isset($payload['startTime'])) {
        $fields['startTime'] = $payload['startTime'];
    }
    if (isset($payload['endTime'])) {
        $fields['endTime'] = $payload['endTime'];
    }
    if (array_key_exists('include_lunch_hour', $payload)) {
        $fields['include_lunch_hour'] = (bool) $payload['include_lunch_hour'];
    }
    if (array_key_exists('lunch_hour_hours', $payload)) {
        $fields['lunch_hour_hours'] = $payload['lunch_hour_hours'];
    }
    if ($scope === 'single' && isset($payload['date'])) {
        $fields['date'] = $payload['date'];
        if ($schedule->series_id) {
            $fields['series_id'] = null;
        }
    }

    $excludeIds = $targets->pluck('id')->map(fn ($id) => (string) $id)->all();

    foreach ($targets as $target) {
        $date = (string) ($fields['date'] ?? Carbon::parse($target->date)->format('Y-m-d'));
        $departmentId = array_key_exists('departmentId', $fields)
          ? $fields['departmentId']
          : $target->departmentId;
        $contractId = $this->contractAssignmentService->resolveContractId(
          (string) $target->employeeId,
          $fields['employmentDetailId'] ?? $target->employmentDetailId,
          $departmentId !== null ? (int) $departmentId : null,
          $date,
          $date,
        );
        $fieldsForTarget = array_merge($fields, [
          'employmentDetailId' => $contractId,
        ]);

        $this->overlapValidator->validate(
          (string) $target->employeeId,
          $date,
          $date,
          $fieldsForTarget['startTime'] ?? $target->startTime,
          $fieldsForTarget['endTime'] ?? $target->endTime,
          null,
          $excludeIds,
        );

        $target->update($fieldsForTarget);
    }

    return $targets->map(fn (ScheduleEmployeeTimesheet $record) => $record->fresh());
  }

  public function delete(ScheduleEmployeeTimesheet $schedule, string $scope = 'single'): int
  {
    if ($scope === 'series' && $schedule->series_id) {
      return ScheduleEmployeeTimesheet::query()
        ->where('series_id', $schedule->series_id)
        ->delete();
    }

    return (int) $schedule->delete();
  }

  /**
   * @param  array{
   *   scope: 'single'|'series',
   *   employeeIds: array<int, string>,
   * }  $payload
   * @return Collection<int, ScheduleEmployeeTimesheet>
   */
  public function copy(ScheduleEmployeeTimesheet $schedule, array $payload): Collection
  {
    $employeeIds = collect($payload['employeeIds'] ?? [])->filter()->unique()->values();
    if ($employeeIds->isEmpty()) {
      throw ValidationException::withMessages([
        'employeeIds' => ['Select at least one employee to copy to.'],
      ]);
    }

    $scope = $payload['scope'] ?? 'single';
    $sourceRows = $scope === 'series' && $schedule->series_id
      ? ScheduleEmployeeTimesheet::query()->where('series_id', $schedule->series_id)->get()
      : collect([$schedule]);

    $created = collect();

    foreach ($employeeIds as $employeeId) {
      if ($employeeId === $schedule->employeeId) {
        continue;
      }

      $newSeriesId = $scope === 'series' ? (string) Str::uuid() : null;

      foreach ($sourceRows as $source) {
        $date = Carbon::parse($source->date)->format('Y-m-d');
        $employmentDetailId = $this->contractAssignmentService->resolveContractId(
          (string) $employeeId,
          null,
          $source->departmentId ? (int) $source->departmentId : null,
          $date,
          $date,
        );

        $this->overlapValidator->validate(
          (string) $employeeId,
          $date,
          $date,
          $source->startTime,
          $source->endTime,
        );

        $created->push(ScheduleEmployeeTimesheet::create([
          'id' => (string) Str::uuid(),
          'employeeId' => $employeeId,
          'employmentDetailId' => $employmentDetailId,
          'departmentId' => $source->departmentId,
          'calendar_group_id' => $source->calendar_group_id,
          'date' => $source->date,
          'startTime' => $source->startTime,
          'endTime' => $source->endTime,
          'include_lunch_hour' => $source->include_lunch_hour,
          'lunch_hour_hours' => $source->lunch_hour_hours,
          'approvalStatus' => 'pending',
          'series_id' => $newSeriesId,
        ]));
      }
    }

    return $created;
  }

  /**
   * @param  array<int, string>  $days
   */
  private function resolveSeriesDates(string $startDate, string $endDate, array $days): Collection
  {
    if ($startDate === '' || $endDate === '') {
      throw ValidationException::withMessages([
        'startDate' => ['Start and end dates are required for a series.'],
      ]);
    }

    $allowedIso = collect($days)
      ->map(fn (string $day) => self::DAY_ISO[$day] ?? null)
      ->filter()
      ->unique()
      ->values();

    if ($allowedIso->isEmpty()) {
      throw ValidationException::withMessages([
        'days' => ['Select at least one weekday for the series.'],
      ]);
    }

    $start = Carbon::parse($startDate)->startOfDay();
    $end = Carbon::parse($endDate)->startOfDay();

    if ($end->lt($start)) {
      throw ValidationException::withMessages([
        'endDate' => ['End date must be on or after the start date.'],
      ]);
    }

    return collect(CarbonPeriod::create($start, $end))
      ->filter(fn (Carbon $day) => $allowedIso->contains($day->dayOfWeekIso))
      ->map(fn (Carbon $day) => $day->format('Y-m-d'))
      ->values();
  }

  /**
   * @param  array<string, mixed>  $payload
   */
  private function hasSeriesDateChanges(array $payload): bool
  {
    return isset($payload['startDate'], $payload['endDate'], $payload['days']);
  }

  /**
   * @param  array<string, mixed>  $payload
   * @return Collection<int, ScheduleEmployeeTimesheet>
   */
  private function regenerateSeries(ScheduleEmployeeTimesheet $schedule, array $payload): Collection
  {
    $seriesId = $schedule->series_id;
    $employeeId = $schedule->employeeId;
    $scheduleGroupId = $schedule->calendar_group_id;
    $excludeIds = ScheduleEmployeeTimesheet::query()
      ->where('series_id', $seriesId)
      ->pluck('id')
      ->map(fn ($id) => (string) $id)
      ->all();

    $dates = $this->resolveSeriesDates(
      (string) $payload['startDate'],
      (string) $payload['endDate'],
      (array) ($payload['days'] ?? []),
    );

    $departmentId = $payload['departmentId'] ?? null;
    $employmentDetailId = $this->contractAssignmentService->resolveContractId(
      (string) $employeeId,
      $payload['employmentDetailId'] ?? null,
      $departmentId !== null ? (int) $departmentId : null,
      (string) $payload['startDate'],
      (string) $payload['endDate'],
    );

    foreach ($dates as $date) {
      $this->overlapValidator->validate(
        (string) $employeeId,
        $date,
        $date,
        $payload['startTime'] ?? '08:00',
        $payload['endTime'] ?? '17:00',
        null,
        $excludeIds,
      );
    }

    ScheduleEmployeeTimesheet::query()->where('series_id', $seriesId)->delete();

    return $dates->map(function (string $date) use ($payload, $seriesId, $employeeId, $scheduleGroupId, $employmentDetailId) {
      return ScheduleEmployeeTimesheet::create([
        'id' => (string) Str::uuid(),
        'employeeId' => $employeeId,
        'employmentDetailId' => $employmentDetailId,
        'departmentId' => $payload['departmentId'] ?? null,
        'calendar_group_id' => $scheduleGroupId,
        'date' => $date,
        'startTime' => $payload['startTime'] ?? '08:00',
        'endTime' => $payload['endTime'] ?? '17:00',
        'include_lunch_hour' => (bool) ($payload['include_lunch_hour'] ?? false),
        'lunch_hour_hours' => $payload['lunch_hour_hours'] ?? 1,
        'approvalStatus' => 'pending',
        'series_id' => $seriesId,
      ]);
    });
  }
}
