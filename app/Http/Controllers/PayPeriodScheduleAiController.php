<?php

namespace App\Http\Controllers;

use App\Models\PayPeriodSchedule;
use App\Models\PayPeriodGroup;
use App\Services\AiSqlGeneratorService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PayPeriodScheduleAiController extends Controller
{
    private AiSqlGeneratorService $aiService;

    public function __construct(AiSqlGeneratorService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Generate SQL from natural language description
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function generateSql(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'description' => ['required', 'string', 'min:10', 'max:2000'],
            'pay_period_group_id' => ['nullable', 'uuid', 'exists:pay_period_groups,id'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], Response::HTTP_BAD_REQUEST);
        }

        $description = $request->input('description');
        $payPeriodGroupId = $request->input('pay_period_group_id');
        
        $result = $this->aiService->generateSqlForPayPeriodSchedule($description);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to generate SQL',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Use provided fields or extract from SQL
        $fields = $result['fields'] ?? $this->extractFieldsFromSql($result['sql']);
        
        // Set pay_period_group_id if provided
        if ($payPeriodGroupId && is_array($fields) && isset($fields['start_date'])) {
            $fields['pay_period_group_id'] = $payPeriodGroupId;
        }
        
        // Convert fields to records array format (for consistency with confirm endpoint)
        // If fields is a single record (has start_date key), wrap it in an array
        // If it's already an array of records, use it as is
        $records = [];
        if (is_array($fields)) {
            if (isset($fields['start_date']) || isset($fields[0])) {
                // Check if it's a single record (has start_date) or already an array
                if (isset($fields['start_date'])) {
                    $records = [$fields]; // Single record, wrap in array
                } else {
                    $records = $fields; // Already an array of records
                    // Set pay_period_group_id for all records if provided
                    if ($payPeriodGroupId) {
                        foreach ($records as &$record) {
                            if (is_array($record) && !isset($record['pay_period_group_id'])) {
                                $record['pay_period_group_id'] = $payPeriodGroupId;
                            }
                        }
                    }
                }
            }
        }

        return response()->json([
            'success' => true,
            'sql' => $result['sql'],
            'records' => $records,
            'operation' => $result['operation'] ?? 'insert',
            'description' => $description,
            'pay_period_group_id' => $payPeriodGroupId,
        ]);
    }

    /**
     * Confirm and execute the records
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function confirmAndExecute(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'records' => ['required', 'array', 'min:1'],
            'records.*.start_date' => ['required', 'date', 'date_format:Y-m-d'],
            'records.*.end_date' => ['required', 'date', 'date_format:Y-m-d', 'after_or_equal:records.*.start_date'],
            'records.*.pay_date' => ['required', 'date', 'date_format:Y-m-d', 'after_or_equal:records.*.end_date'],
            'records.*.pay_period_group_id' => ['nullable', 'uuid', 'exists:pay_period_groups,id'],
            'operation' => ['required', 'in:insert,update'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        // Add conditional validation for update operation
        if ($request->input('operation') === 'update') {
            $validator->sometimes('records.*.id', ['required', 'uuid', 'exists:pay_period_schedule,id'], function ($input) {
                return $input->operation === 'update';
            });
        }

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], Response::HTTP_BAD_REQUEST);
        }

        $records = $request->input('records');
        $operation = $request->input('operation');

        try {
            DB::beginTransaction();

            $results = [];
            $errors = [];

            foreach ($records as $index => $record) {
                try {
                    if ($operation === 'insert') {
                        // Remove id from record if present (will be auto-generated)
                        unset($record['id']);
                        unset($record['created_at'], $record['updated_at'], $record['payrate_frequency_id']);
                        
                        $payPeriodSchedule = PayPeriodSchedule::create($record);
                        $results[] = [
                            'index' => $index,
                            'success' => true,
                            'data' => $payPeriodSchedule->load(['payPeriodGroup']),
                        ];
                    } else {
                        // For update, we need the ID
                        $id = $record['id'] ?? null;
                        if (!$id) {
                            throw new \Exception('ID is required for update operation');
                        }

                        // Remove id and timestamps from update fields
                        $updateFields = $record;
                        unset($updateFields['id'], $updateFields['created_at'], $updateFields['payrate_frequency_id']);
                        
                        $payPeriodSchedule = PayPeriodSchedule::findOrFail($id);
                        $payPeriodSchedule->update($updateFields);
                        $results[] = [
                            'index' => $index,
                            'success' => true,
                            'data' => $payPeriodSchedule->load(['payPeriodGroup']),
                        ];
                    }
                } catch (\Exception $e) {
                    $errors[] = [
                        'index' => $index,
                        'error' => $e->getMessage(),
                    ];
                    Log::error('Error processing pay period schedule record', [
                        'index' => $index,
                        'error' => $e->getMessage(),
                        'record' => $record,
                    ]);
                }
            }

            // If all records failed, rollback
            if (empty($results) && !empty($errors)) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'error' => 'All records failed to process',
                    'errors' => $errors,
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            // If some records failed, commit successful ones and return partial success
            if (!empty($errors)) {
                DB::commit();
                return response()->json([
                    'success' => true,
                    'message' => 'Some records processed successfully, some failed',
                    'results' => $results,
                    'errors' => $errors,
                ], Response::HTTP_OK);
            }

            // All records succeeded
            DB::commit();

            // Save rules to pay period group if provided and this is the first set of records
            $description = $request->input('description');
            if ($description && $operation === 'insert' && !empty($records)) {
                $firstRecord = $records[0];
                $payPeriodGroupId = $firstRecord['pay_period_group_id'] ?? null;
                
                if ($payPeriodGroupId) {
                    $payPeriodGroup = PayPeriodGroup::find($payPeriodGroupId);
                    if ($payPeriodGroup && empty($payPeriodGroup->rules)) {
                        // Only save if rules is not already set (first time)
                        $payPeriodGroup->update(['rules' => $description]);
                        Log::info('Saved rules to pay period group', [
                            'pay_period_group_id' => $payPeriodGroupId,
                        ]);
                    }
                }
            }

            $message = $operation === 'insert' 
                ? 'Pay period schedule(s) created successfully'
                : 'Pay period schedule(s) updated successfully';

            return response()->json([
                'success' => true,
                'message' => $message,
                'results' => $results,
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error executing pay period schedule records', [
                'error' => $e->getMessage(),
                'records' => $records,
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to execute: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Extract fields from SQL (fallback if AI doesn't provide fields)
     */
    private function extractFieldsFromSql(string $sql): array
    {
        $fields = [];

        // Extract INSERT values
        if (preg_match('/INSERT\s+INTO\s+pay_period_schedule\s*\(([^)]+)\)\s*VALUES\s*\(([^)]+)\)/i', $sql, $matches)) {
            $columns = array_map('trim', explode(',', $matches[1]));
            $values = array_map('trim', explode(',', $matches[2]));

            foreach ($columns as $index => $column) {
                $column = trim($column, '`"\'');
                $value = trim($values[$index] ?? '', " '\"");
                if ($value !== 'NULL' && $value !== 'NOW()' && $value !== 'UUID()') {
                    $fields[$column] = $value;
                } elseif ($value === 'NULL') {
                    $fields[$column] = null;
                }
            }
        }

        // Extract UPDATE SET values
        if (preg_match('/UPDATE\s+pay_period_schedule\s+SET\s+(.+?)(?:\s+WHERE|$)/i', $sql, $matches)) {
            $setClause = $matches[1];
            preg_match_all('/(\w+)\s*=\s*([^,]+)/i', $setClause, $setMatches, PREG_SET_ORDER);
            foreach ($setMatches as $match) {
                $column = trim($match[1]);
                $value = trim($match[2], " '\"");
                if ($value !== 'NULL' && $value !== 'NOW()') {
                    $fields[$column] = $value;
                } elseif ($value === 'NULL') {
                    $fields[$column] = null;
                }
            }
        }

        return $fields;
    }

    /**
     * Basic SQL safety validation
     */
    private function isSqlSafe(string $sql, string $operation): bool
    {
        $sql = strtoupper(trim($sql));

        // Only allow INSERT or UPDATE
        if ($operation === 'insert' && !str_starts_with($sql, 'INSERT')) {
            return false;
        }
        if ($operation === 'update' && !str_starts_with($sql, 'UPDATE')) {
            return false;
        }

        // Must target the correct table
        if (!preg_match('/pay_period_schedule/i', $sql)) {
            return false;
        }

        // Disallow dangerous operations
        $dangerous = ['DROP', 'DELETE', 'TRUNCATE', 'ALTER', 'CREATE', 'GRANT', 'REVOKE'];
        foreach ($dangerous as $keyword) {
            if (str_contains($sql, $keyword)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate fields for the operation
     */
    private function validateFields(array $fields, string $operation): array
    {
        $rules = [
            'start_date' => ['required', 'date', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'pay_date' => ['required', 'date', 'date_format:Y-m-d', 'after_or_equal:end_date'],
            'pay_period_group_id' => ['nullable', 'uuid', 'exists:pay_period_groups,id'],
        ];

        if ($operation === 'insert') {
            $rules['id'] = ['nullable', 'uuid'];
        } else {
            $rules['id'] = ['required', 'uuid', 'exists:pay_period_schedule,id'];
        }

        $validator = Validator::make($fields, $rules);

        if ($validator->fails()) {
            return [
                'valid' => false,
                'error' => 'Field validation failed',
                'errors' => $validator->errors()->toArray(),
            ];
        }

        return ['valid' => true];
    }
}
