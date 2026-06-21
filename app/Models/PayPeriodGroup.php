<?php

namespace App\Models;

use App\Services\AiSqlGeneratorService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PayPeriodGroup extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'pay_period_groups';

    protected $fillable = [
        'id',
        'name',
        'status',
        'isDefault',
        'rules',
    ];

    protected $casts = [
        'isDefault' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $model): void {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });

        // Ensure only one record can be default
        static::saving(function (self $model): void {
            if ($model->isDefault) {
                // Set all other records to isDefault = false
                static::where('id', '!=', $model->id)
                    ->where('isDefault', true)
                    ->update(['isDefault' => false]);
            }
        });

        // Generate schedules after creation
        static::created(function (self $model): void {
            if ($model->rules) {
                $model->generateInitialSchedules();
            }
        });

        // Generate schedules after update if rules changed and no schedules exist
        static::updated(function (self $model): void {
            if ($model->rules && $model->wasChanged('rules')) {
                // Check if schedules already exist
                $existingSchedulesCount = $model->payPeriodSchedules()->count();
                if ($existingSchedulesCount === 0) {
                    $model->generateInitialSchedules();
                }
            }
        });
    }

    /**
     * Generate the first 4 pay period schedules using AI service
     */
    public function generateInitialSchedules(): void
    {
        if (!$this->rules) {
            return;
        }

        try {
            $aiService = app(AiSqlGeneratorService::class);
            
            // Generate first schedule using rules
            $result = $aiService->generateSqlForPayPeriodSchedule($this->rules);
            
            if (!$result['success'] || !isset($result['fields'])) {
                Log::error('Failed to generate initial pay period schedule', [
                    'pay_period_group_id' => $this->id,
                    'error' => $result['error'] ?? 'Unknown error',
                ]);
                return;
            }

            $schedules = [];
            $currentFields = $result['fields'];
            
            // Ensure pay_period_group_id is set
            $currentFields['pay_period_group_id'] = $this->id;
            
            // Create first schedule
            $firstSchedule = PayPeriodSchedule::create($currentFields);
            $schedules[] = $firstSchedule->toArray();

            // Generate next 3 schedules using the pattern
            for ($i = 0; $i < 3; $i++) {
                $nextResult = $aiService->generateNextPayPeriodSchedule(
                    $schedules,
                    $this->rules,
                    $this->id
                );

                if (!$nextResult['success'] || !isset($nextResult['fields'])) {
                    Log::warning('Failed to generate next pay period schedule', [
                        'pay_period_group_id' => $this->id,
                        'iteration' => $i + 1,
                        'error' => $nextResult['error'] ?? 'Unknown error',
                    ]);
                    break;
                }

                $nextFields = $nextResult['fields'];
                $nextFields['pay_period_group_id'] = $this->id;

                $nextSchedule = PayPeriodSchedule::create($nextFields);
                $schedules[] = $nextSchedule->toArray();
            }

            Log::info('Generated initial pay period schedules', [
                'pay_period_group_id' => $this->id,
                'count' => count($schedules),
            ]);
        } catch (\Exception $e) {
            Log::error('Exception generating initial pay period schedules', [
                'pay_period_group_id' => $this->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    public function payPeriodSchedules(): HasMany
    {
        return $this->hasMany(PayPeriodSchedule::class, 'pay_period_group_id');
    }

    public function employmentDetails(): HasMany
    {
        return $this->hasMany(EmploymentDetail::class, 'defaultPayPeriodGroupId');
    }
}
