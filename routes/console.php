<?php

use App\Models\AgentMemory;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\RunTaskJob;
use App\Models\Task;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('ai:memory:health {--user-id=} {--json}', function (): int {
    /** @var \App\Services\AgentMemoryService $memory */
    $memory = app(\App\Services\AgentMemoryService::class);
    $userIdOpt = $this->option('user-id');
    $userId = is_numeric($userIdOpt) ? (int) $userIdOpt : null;

    $metrics = $memory->healthMetrics($userId);
    if ((bool) $this->option('json')) {
        $this->line(json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
        return Command::SUCCESS;
    }

    if (! ((bool) ($metrics['ready'] ?? false))) {
        $this->warn('Agent memory table is not ready or metrics query failed.');
        if (isset($metrics['error'])) {
            $this->line('error: '.(string) $metrics['error']);
        }
        return Command::SUCCESS;
    }

    $this->info('Agent Memory Health');
    $this->table(
        ['Metric', 'Value'],
        [
            ['user_id', (string) ($metrics['user_id'] ?? 'all')],
            ['total_memories', (string) ($metrics['total_memories'] ?? 0)],
            ['recent_24h', (string) ($metrics['recent_24h'] ?? 0)],
            ['recent_access_1h', (string) ($metrics['recent_access_1h'] ?? 0)],
            ['ollama_embeddings', (string) ($metrics['ollama_embeddings'] ?? 0)],
            ['fallback_embeddings', (string) ($metrics['fallback_embeddings'] ?? 0)],
            ['embedding_coverage_percent', (string) ($metrics['embedding_coverage_percent'] ?? 0)],
            ['avg_content_length', (string) ($metrics['avg_content_length'] ?? 0)],
        ]
    );

    /** @var array<int, array{scope: string, count: int}> $scopes */
    $scopes = is_array($metrics['top_scopes'] ?? null) ? $metrics['top_scopes'] : [];
    if ($scopes !== []) {
        $this->table(['Top Scope', 'Count'], array_map(
            fn (array $row): array => [(string) ($row['scope'] ?? ''), (string) ($row['count'] ?? 0)],
            $scopes
        ));
    }

    return Command::SUCCESS;
})->purpose('Show embedding coverage and memory health metrics.');

Artisan::command('ai:memory:benchmark {--user-id=} {--query=} {--iterations=5} {--limit=4} {--json}', function (): int {
    /** @var \App\Services\AgentMemoryService $memory */
    $memory = app(\App\Services\AgentMemoryService::class);
    $query = trim((string) ($this->option('query') ?: 'Summarize my last task and tool outcomes'));
    $iterations = max(1, (int) $this->option('iterations'));
    $limit = max(1, min(20, (int) $this->option('limit')));
    $userIdOpt = $this->option('user-id');
    $resolvedUserId = is_numeric($userIdOpt) ? (int) $userIdOpt : null;

    if ($resolvedUserId === null) {
        try {
            $resolvedUserId = (int) (AgentMemory::query()->orderByDesc('created_at')->value('user_id') ?? 0);
        } catch (\Throwable) {
            $this->error('Could not auto-resolve user_id from agent_memories. Pass --user-id=<id>.');
            return Command::FAILURE;
        }
    }

    if ($resolvedUserId <= 0) {
        $this->error('No user_id provided and no agent_memories rows exist. Pass --user-id=<id>.');
        return Command::FAILURE;
    }

    $latencyMs = [];
    $hits = [];
    $storedIds = [];

    for ($i = 1; $i <= $iterations; $i++) {
        $storeStart = hrtime(true);
        $stored = $memory->storeEpisode(
            userId: $resolvedUserId,
            scope: 'benchmark',
            memoryKey: 'benchmark_'.$i,
            content: "Benchmark sample {$i}. Query seed: {$query}",
            metadata: ['source' => 'ai:memory:benchmark', 'iteration' => $i],
        );
        $storeMs = (hrtime(true) - $storeStart) / 1_000_000;
        if ($stored->exists && is_string($stored->id) && $stored->id !== '') {
            $storedIds[] = $stored->id;
        }

        $retrieveStart = hrtime(true);
        $result = $memory->retrieveRelevant($resolvedUserId, $query, $limit);
        $retrieveMs = (hrtime(true) - $retrieveStart) / 1_000_000;

        $latencyMs[] = [
            'store_ms' => $storeMs,
            'retrieve_ms' => $retrieveMs,
            'total_ms' => $storeMs + $retrieveMs,
        ];
        $hits[] = count($result);
    }

    if ($storedIds !== []) {
        try {
            AgentMemory::query()->whereIn('id', $storedIds)->delete();
        } catch (\Throwable) {
            // Non-blocking cleanup.
        }
    }

    $percentile = function (array $values, float $p): float {
        sort($values);
        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }
        $index = max(0, min($count - 1, (int) ceil(($p / 100) * $count) - 1));
        return (float) $values[$index];
    };

    $storeValues = array_map(fn (array $row): float => (float) $row['store_ms'], $latencyMs);
    $retrieveValues = array_map(fn (array $row): float => (float) $row['retrieve_ms'], $latencyMs);
    $totalValues = array_map(fn (array $row): float => (float) $row['total_ms'], $latencyMs);

    $summary = [
        'user_id' => $resolvedUserId,
        'query' => $query,
        'iterations' => $iterations,
        'limit' => $limit,
        'hit_rate_percent' => round((count(array_filter($hits, fn (int $n): bool => $n > 0)) / max(1, $iterations)) * 100, 2),
        'avg_hits' => round(array_sum($hits) / max(1, count($hits)), 2),
        'store_ms' => [
            'avg' => round(array_sum($storeValues) / max(1, count($storeValues)), 2),
            'p95' => round($percentile($storeValues, 95), 2),
        ],
        'retrieve_ms' => [
            'avg' => round(array_sum($retrieveValues) / max(1, count($retrieveValues)), 2),
            'p95' => round($percentile($retrieveValues, 95), 2),
        ],
        'total_ms' => [
            'avg' => round(array_sum($totalValues) / max(1, count($totalValues)), 2),
            'p95' => round($percentile($totalValues, 95), 2),
        ],
    ];

    if ((bool) $this->option('json')) {
        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
        return Command::SUCCESS;
    }

    $this->info('Agent Memory Benchmark');
    $this->table(
        ['Metric', 'Value'],
        [
            ['user_id', (string) $summary['user_id']],
            ['iterations', (string) $summary['iterations']],
            ['limit', (string) $summary['limit']],
            ['hit_rate_percent', (string) $summary['hit_rate_percent']],
            ['avg_hits', (string) $summary['avg_hits']],
            ['store_ms_avg', (string) $summary['store_ms']['avg']],
            ['store_ms_p95', (string) $summary['store_ms']['p95']],
            ['retrieve_ms_avg', (string) $summary['retrieve_ms']['avg']],
            ['retrieve_ms_p95', (string) $summary['retrieve_ms']['p95']],
            ['total_ms_avg', (string) $summary['total_ms']['avg']],
            ['total_ms_p95', (string) $summary['total_ms']['p95']],
        ]
    );

    return Command::SUCCESS;
})->purpose('Benchmark memory store+retrieval latency and hit rate.');

Schedule::call(function (): void {
    Task::query()
        ->where('status', 'pending')
        ->where('schedule_type', 'recurring')
        ->whereNotNull('next_run_at')
        ->where('next_run_at', '<=', now())
        ->each(function (Task $task): void {
            RunTaskJob::dispatch($task->id);
            $task->update(['status' => 'queued']);
        });
})->everyMinute();
