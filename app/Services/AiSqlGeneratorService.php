<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiSqlGeneratorService
{
    private string $apiUrl;
    private ?string $apiKey;

    public function __construct()
    {
        // Use OpenAI API by default, but can be configured for other providers
        $this->apiUrl = config('services.ai.api_url', 'https://api.openai.com/v1/chat/completions');
        $this->apiKey = config('services.ai.api_key');
    }

    /**
     * Generate SQL from natural language description for pay_period_schedule table
     *
     * @param string $description
     * @return array{success: bool, sql?: string, fields?: array, error?: string}
     */
    public function generateSqlForPayPeriodSchedule(string $description): array
    {
        if (!$this->apiKey) {
            return [
                'success' => false,
                'error' => 'AI API key not configured. Please set AI_API_KEY in your .env file.',
            ];
        }

        $systemPrompt = $this->getSystemPrompt();
        $userPrompt = $this->getUserPrompt($description);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post($this->apiUrl, [
                'model' => config('services.ai.model', 'gpt-4'),
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.3,
                'response_format' => ['type' => 'json_object'],
            ]);

            if (!$response->successful()) {
                Log::error('AI API Error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'success' => false,
                    'error' => 'Failed to generate SQL. AI service returned an error.',
                ];
            }

            $data = $response->json();
            $content = $data['choices'][0]['message']['content'] ?? null;

            if (!$content) {
                return [
                    'success' => false,
                    'error' => 'No response from AI service.',
                ];
            }

            $parsed = json_decode($content, true);

            if (!$parsed || !isset($parsed['sql'])) {
                return [
                    'success' => false,
                    'error' => 'Invalid response format from AI service.',
                ];
            }

            return [
                'success' => true,
                'sql' => $parsed['sql'],
                'fields' => $parsed['fields'] ?? [],
                'operation' => $parsed['operation'] ?? 'insert',
            ];
        } catch (\Exception $e) {
            Log::error('AI Service Exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => 'An error occurred while generating SQL: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get the system prompt for the AI
     */
    private function getSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a SQL expert specializing in Laravel/Eloquent database operations. 
Your task is to generate SQL INSERT or UPDATE statements for the `pay_period_schedule` table based on natural language descriptions.

Table structure:
- id (UUID, primary key)
- start_date (DATE, required)
- end_date (DATE, required)
- pay_date (DATE, required)
- pay_period_group_id (UUID, nullable, foreign key to pay_period_groups)
- created_at (TIMESTAMP)
- updated_at (TIMESTAMP)

Rules:
1. Always return a JSON object with this structure:
   {
     "operation": "insert" or "update",
     "sql": "the SQL statement",
     "fields": {
       "start_date": "YYYY-MM-DD",
       "end_date": "YYYY-MM-DD",
       "pay_date": "YYYY-MM-DD",
       "pay_period_group_id": "uuid or null"
     }
   }

2. For INSERT: Generate a UUID for the id field. Use NOW() for created_at and updated_at.
3. For UPDATE: Include a WHERE clause. You may need to infer which record to update from context.
4. Dates must be in YYYY-MM-DD format.
5. If pay_period_group_id is mentioned, include it. Otherwise, set to null.
6. Only generate valid SQL that can be executed safely.

Return ONLY the JSON object, no additional text.
PROMPT;
    }

    /**
     * Get the user prompt with the description
     */
    private function getUserPrompt(string $description): string
    {
        return <<<PROMPT
Generate SQL for the pay_period_schedule table based on this description:

{$description}

Return the JSON object with the SQL and fields as specified.
PROMPT;
    }

    /**
     * Generate next pay period schedule based on existing records and description
     *
     * @param array $existingSchedules Array of existing PayPeriodSchedule records
     * @param string|null $description Original description used to create the pattern
     * @param string|null $payPeriodGroupId The pay period group ID
     * @return array{success: bool, sql?: string, fields?: array, error?: string}
     */
    public function generateNextPayPeriodSchedule(array $existingSchedules, ?string $description = null, ?string $payPeriodGroupId = null): array
    {
        if (!$this->apiKey) {
            return [
                'success' => false,
                'error' => 'AI API key not configured. Please set AI_API_KEY in your .env file.',
            ];
        }

        // Build context from existing schedules
        $context = $this->buildContextFromSchedules($existingSchedules, $description);

        $systemPrompt = $this->getNextScheduleSystemPrompt();
        $userPrompt = $this->getNextScheduleUserPrompt($context, $payPeriodGroupId);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post($this->apiUrl, [
                'model' => config('services.ai.model', 'gpt-4'),
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.3,
                'response_format' => ['type' => 'json_object'],
            ]);

            if (!$response->successful()) {
                Log::error('AI API Error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'success' => false,
                    'error' => 'Failed to generate next schedule. AI service returned an error.',
                ];
            }

            $data = $response->json();
            $content = $data['choices'][0]['message']['content'] ?? null;

            if (!$content) {
                return [
                    'success' => false,
                    'error' => 'No response from AI service.',
                ];
            }

            $parsed = json_decode($content, true);

            if (!$parsed || !isset($parsed['fields'])) {
                return [
                    'success' => false,
                    'error' => 'Invalid response format from AI service.',
                ];
            }

            // Ensure pay_period_group_id is set
            if ($payPeriodGroupId && !isset($parsed['fields']['pay_period_group_id'])) {
                $parsed['fields']['pay_period_group_id'] = $payPeriodGroupId;
            }

            return [
                'success' => true,
                'sql' => $parsed['sql'] ?? null,
                'fields' => $parsed['fields'],
                'operation' => 'insert',
            ];
        } catch (\Exception $e) {
            Log::error('AI Service Exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => 'An error occurred while generating next schedule: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Build context string from existing schedules
     */
    private function buildContextFromSchedules(array $schedules, ?string $description): string
    {
        $context = "Existing pay period schedules:\n\n";
        
        foreach ($schedules as $schedule) {
            $context .= sprintf(
                "- Start: %s, End: %s, Pay Date: %s\n",
                $schedule['start_date'] ?? $schedule->start_date ?? 'N/A',
                $schedule['end_date'] ?? $schedule->end_date ?? 'N/A',
                $schedule['pay_date'] ?? $schedule->pay_date ?? 'N/A'
            );
        }

        if ($description) {
            $context .= "\nOriginal description/pattern: {$description}\n";
        }

        return $context;
    }

    /**
     * Get system prompt for generating next schedule
     */
    private function getNextScheduleSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a SQL expert specializing in Laravel/Eloquent database operations. 
Your task is to generate the NEXT pay period schedule record based on existing schedules and a pattern description.

Table structure:
- id (UUID, primary key)
- start_date (DATE, required)
- end_date (DATE, required)
- pay_date (DATE, required)
- pay_period_group_id (UUID, nullable, foreign key to pay_period_groups)
- created_at (TIMESTAMP)
- updated_at (TIMESTAMP)

Rules:
1. Analyze the existing schedules to identify the pattern (frequency, duration, pay date offset)
2. Generate the NEXT schedule that follows the same pattern
3. Always return a JSON object with this structure:
   {
     "operation": "insert",
     "sql": "the SQL statement (optional)",
     "fields": {
       "start_date": "YYYY-MM-DD",
       "end_date": "YYYY-MM-DD",
       "pay_date": "YYYY-MM-DD",
       "pay_period_group_id": "uuid or null"
     }
   }

4. Dates must be in YYYY-MM-DD format
5. The pay_date should follow the same pattern as existing schedules (e.g., if pay dates are always 5 days after end_date, maintain that)
6. Calculate the next period based on the most recent schedule

Return ONLY the JSON object, no additional text.
PROMPT;
    }

    /**
     * Get user prompt for generating next schedule
     */
    private function getNextScheduleUserPrompt(string $context, ?string $payPeriodGroupId): string
    {
        $groupContext = $payPeriodGroupId ? "\nPay Period Group ID: {$payPeriodGroupId}" : '';
        
        return <<<PROMPT
{$context}
{$groupContext}

Generate the NEXT pay period schedule that follows the established pattern. Return the JSON object with the fields as specified.
PROMPT;
    }
}
