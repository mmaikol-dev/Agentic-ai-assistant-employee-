<?php

namespace App\Services;

use App\Jobs\RunTaskJob;
use App\Models\Task;
use App\Models\TaskLog;
use App\Models\TaskRun;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TaskService
{
    public function createFromToolCall(array $payload, int $userId, ?string $chatMessageId = null): Task
    {
        $timezone = $this->resolveTimezone($payload['timezone'] ?? null);
        $scheduleType = (string) ($payload['schedule_type'] ?? 'immediate');
        $runAt = $this->normalizeRunAt($payload['run_at'] ?? null, $timezone);
        $runAt = $this->adjustOneTimeRunAtForRelativeIntent(
            $runAt,
            $scheduleType,
            $timezone,
            $payload['original_user_request'] ?? null,
        );
        $this->validateSchedule($scheduleType, $runAt, $timezone);

        $task = Task::create([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'title' => (string) ($payload['title'] ?? 'Untitled task'),
            'description' => $payload['description'] ?? null,
            'created_from' => 'chat',
            'chat_message_id' => $chatMessageId,
            'status' => 'pending',
            'priority' => $payload['priority'] ?? 'normal',
            'schedule_type' => $scheduleType,
            'run_at' => $runAt,
            'cron_expression' => $payload['cron_expression'] ?? null,
            'cron_human' => $payload['cron_human'] ?? null,
            'event_condition' => $payload['event_condition'] ?? null,
            'timezone' => $timezone,
            'execution_plan' => $payload['execution_plan'] ?? [],
            'expected_output' => $payload['expected_output'] ?? null,
            'original_user_request' => $payload['original_user_request'] ?? null,
            'next_run_at' => $this->initialNextRunAt(
                $scheduleType,
                $runAt,
                $payload['cron_expression'] ?? null,
                $timezone,
            ),
        ]);

        if ($task->schedule_type === 'immediate') {
            RunTaskJob::dispatch($task->id);
            $task->update(['status' => 'queued']);
        }

        if ($task->schedule_type === 'one_time' && $task->run_at !== null) {
            RunTaskJob::dispatch($task->id)->delay($task->run_at);
            $task->update([
                'status' => 'queued',
                'next_run_at' => $task->run_at,
            ]);
        }

        return $task->fresh();
    }

    public function startRun(Task $task): TaskRun
    {
        $task->update([
            'status' => 'running',
            'last_run_at' => now(),
            'next_run_at' => null,
        ]);

        return TaskRun::create([
            'id' => (string) Str::uuid(),
            'task_id' => $task->id,
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    public function logStep(Task $task, TaskRun $run, array $data): TaskLog
    {
        return TaskLog::create([
            'id' => (string) Str::uuid(),
            'task_id' => $task->id,
            'run_id' => $run->id,
            'step' => (int) ($data['step'] ?? 0),
            'status' => (string) ($data['status'] ?? 'running'),
            'thought' => $data['thought'] ?? null,
            'action' => $data['action'] ?? null,
            'observation' => $data['observation'] ?? null,
            'tool_used' => $data['tool_used'] ?? null,
            'tool_input' => $data['tool_input'] ?? null,
            'tool_output' => $data['tool_output'] ?? null,
            'logged_at' => now(),
        ]);
    }

    public function completeRun(Task $task, TaskRun $run, array $output, string $summary): void
    {
        $run->update([
            'status' => 'completed',
            'completed_at' => now(),
            'output' => $output,
            'summary' => $summary,
        ]);

        if ($task->schedule_type === 'recurring' && is_string($task->cron_expression) && $task->cron_expression !== '') {
            $nextRun = $this->getNextCronRun($task->cron_expression, (string) $task->timezone);
            $task->update([
                'status' => 'pending',
                'next_run_at' => $nextRun,
            ]);
            RunTaskJob::dispatch($task->id)->delay($nextRun);
            return;
        }

        if ($task->schedule_type === 'event_triggered') {
            $task->update(['status' => 'pending']);
            return;
        }

        $task->update(['status' => 'completed']);
    }

    public function failRun(Task $task, TaskRun $run, string $error): void
    {
        $run->update([
            'status' => 'failed',
            'completed_at' => now(),
            'error' => $error,
        ]);

        $task->update(['status' => 'failed']);
    }

    public function getNextCronRun(string $cron, string $timezone): CarbonInterface
    {
        if (class_exists(\Cron\CronExpression::class)) {
            $expression = \Cron\CronExpression::factory($cron);

            return Carbon::parse(
                $expression->getNextRunDate('now', 0, false, $timezone),
            );
        }

        return now($timezone)->addDay();
    }

    private function normalizeRunAt(mixed $value, string $timezone): ?CarbonInterface
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value, $timezone);
        } catch (\Throwable) {
            return null;
        }
    }

    private function adjustOneTimeRunAtForRelativeIntent(
        ?CarbonInterface $runAt,
        string $scheduleType,
        string $timezone,
        mixed $originalUserRequest,
    ): ?CarbonInterface {
        if ($scheduleType !== 'one_time' || $runAt === null || ! is_string($originalUserRequest)) {
            return $runAt;
        }

        $requestText = strtolower(trim($originalUserRequest));
        if ($requestText === '') {
            return $runAt;
        }

        $mentionsRelativeDay = preg_match('/\b(today|tonight|this morning|this afternoon|this evening)\b/i', $requestText) === 1;
        $mentionsThisYear = preg_match('/\bthis year\b/i', $requestText) === 1;

        if (! $mentionsRelativeDay && ! $mentionsThisYear) {
            return $runAt;
        }

        $now = now($timezone);

        // If model produced an outdated year while user said "this year", align it.
        if ($mentionsThisYear && $runAt->year !== $now->year) {
            $runAt = $runAt->copy()->year($now->year);
        }

        if ($runAt->greaterThan($now)) {
            return $runAt;
        }

        if ($mentionsRelativeDay) {
            // Keep requested clock time, move to next valid occurrence.
            $candidate = $now->copy()->setTime($runAt->hour, $runAt->minute, $runAt->second);
            if ($candidate->lessThanOrEqualTo($now)) {
                $candidate->addDay();
            }

            return $candidate;
        }

        return $runAt;
    }

    private function validateSchedule(string $scheduleType, ?CarbonInterface $runAt, string $timezone): void
    {
        if ($scheduleType !== 'one_time') {
            return;
        }

        if ($runAt === null) {
            throw ValidationException::withMessages([
                'run_at' => 'One-time tasks require a valid run_at datetime.',
            ]);
        }

        if ($runAt->lessThanOrEqualTo(now($timezone))) {
            throw ValidationException::withMessages([
                'run_at' => 'run_at must be in the future for one-time tasks.',
            ]);
        }
    }

    private function resolveTimezone(mixed $timezone): string
    {
        $fallback = (string) config('app.timezone', 'UTC');
        if (! is_string($timezone) || trim($timezone) === '') {
            return $fallback;
        }

        try {
            new \DateTimeZone($timezone);
            return $timezone;
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private function initialNextRunAt(
        string $scheduleType,
        ?CarbonInterface $runAt,
        mixed $cronExpression,
        string $timezone,
    ): ?CarbonInterface
    {
        if ($scheduleType === 'one_time') {
            return $runAt;
        }

        if ($scheduleType === 'recurring' && is_string($cronExpression) && trim($cronExpression) !== '') {
            return $this->getNextCronRun($cronExpression, $timezone);
        }

        return null;
    }
}
