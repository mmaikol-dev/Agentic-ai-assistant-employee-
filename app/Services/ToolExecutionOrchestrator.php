<?php

namespace App\Services;

use Illuminate\Support\Str;

class ToolExecutionOrchestrator
{
    /**
     * @param array<string, mixed> $args
     * @param callable(string, array<string, mixed>): array<string, mixed> $executor
     * @return array<string, mixed>
     */
    public function execute(string $toolName, array $args, callable $executor, int $maxAttempts = 3): array
    {
        $attempts = [];
        $currentArgs = $args;
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $result = $executor($toolName, $currentArgs);
            $attempts[] = [
                'attempt' => $attempt,
                'args' => $currentArgs,
                'result_type' => (string) ($result['type'] ?? 'unknown'),
                'message' => (string) ($result['message'] ?? ''),
            ];

            $isError = ($result['type'] ?? null) === 'error';
            if (! $isError) {
                $result['_execution'] = [
                    'attempts' => $attempt,
                    'history' => $attempts,
                    'recovered' => $attempt > 1,
                ];

                return $result;
            }

            $lastError = $result;

            if ($attempt < $maxAttempts) {
                usleep(200000 * $attempt);
                $currentArgs = $this->alternativeArgs($toolName, $currentArgs, $attempt, $lastError);
                continue;
            }

            $lastMessage = trim((string) ($lastError['message'] ?? ''));
            $message = $lastMessage !== ''
                ? "Tool execution failed after retries: {$lastMessage}"
                : 'Tool execution failed after retries.';

            return [
                'type' => 'error',
                'message' => $message,
                'tool' => $toolName,
                'last_error' => $lastError,
                '_execution' => [
                    'attempts' => $attempt,
                    'history' => $attempts,
                    'recovered' => false,
                ],
            ];
        }

        return [
            'type' => 'error',
            'message' => 'Unexpected orchestrator state.',
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @param array<string, mixed>|null $lastError
     * @return array<string, mixed>
     */
    private function alternativeArgs(string $toolName, array $args, int $attempt, ?array $lastError = null): array
    {
        $normalized = collect($args)
            ->reject(fn ($v) => $v === null || $v === '')
            ->map(fn ($v) => is_string($v) ? trim($v) : $v)
            ->all();

        if ($toolName === 'send_whatsapp_message' && isset($normalized['to']) && is_string($normalized['to'])) {
            $digits = preg_replace('/\D+/', '', $normalized['to']) ?? '';
            if ($digits !== '') {
                $normalized['to'] = str_starts_with($digits, '254') ? '+'.$digits : '+'.$digits;
            }
        }

        if ($toolName === 'financial_report' && $attempt >= 2) {
            unset($normalized['city']);
        }

        if ($toolName === 'model_schema_workspace') {
            $lastMessage = Str::lower((string) ($lastError['message'] ?? ''));
            if (str_contains($lastMessage, 'could not resolve model/table')) {
                $table = isset($normalized['table']) ? trim((string) $normalized['table']) : '';
                $model = isset($normalized['model']) ? trim((string) $normalized['model']) : '';

                if ($table !== '' && str_ends_with($table, '_messages')) {
                    $table = (string) Str::replaceLast('_messages', '', $table);
                    $normalized['table'] = $table;
                }

                if ($model === '' && $table !== '') {
                    $normalized['model'] = Str::studly(Str::singular($table));
                }

                if ($table === '' && $model !== '') {
                    $modelBase = class_basename(str_replace('App\\Models\\', '', $model));
                    $normalized['table'] = Str::snake(Str::plural($modelBase));
                }
            }
        }

        return $normalized;
    }
}
