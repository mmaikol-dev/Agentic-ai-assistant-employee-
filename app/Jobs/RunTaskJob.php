<?php

namespace App\Jobs;

use App\Models\Task;
use App\Services\OllamaToolRunner;
use App\Services\TaskService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunTaskJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    public function __construct(private readonly string $taskId) {}

    public function handle(TaskService $taskService): void
    {
        $task = Task::query()->find($this->taskId);
        if ($task === null || $task->status === 'cancelled') {
            return;
        }

        $run = $taskService->startRun($task);

        try {
            $executionPlan = is_array($task->execution_plan) ? $task->execution_plan : [];
            $results = [];
            $hasFailures = false;
            $runner = new OllamaToolRunner(
                (string) config('services.ollama.model'),
                (string) \Illuminate\Support\Str::uuid(),
                (int) $task->user_id,
            );

            foreach ($executionPlan as $stepDef) {
                if (! is_array($stepDef)) {
                    continue;
                }

                $stepNumber = (int) ($stepDef['step'] ?? 0);
                $tool = (string) ($stepDef['tool'] ?? '');
                $input = $stepDef['tool_input'] ?? [];
                if (! is_array($input)) {
                    $input = [];
                }

                $taskService->logStep($task, $run, [
                    'step' => $stepNumber,
                    'status' => 'running',
                    'thought' => "THOUGHT: Starting step {$stepNumber}.",
                    'action' => 'ACTION: '.(string) ($stepDef['action'] ?? 'Execute planned step'),
                    'tool_used' => $tool !== '' ? $tool : null,
                    'tool_input' => $input === [] ? null : $input,
                ]);

                if ($tool !== '') {
                    $toolResult = $runner->callTool($tool, $input);
                    $results[(string) $stepNumber] = $toolResult;
                    $resultType = (string) ($toolResult['type'] ?? 'unknown');
                    $stepStatus = in_array($resultType, ['error', 'policy_blocked'], true)
                        ? 'failed'
                        : 'completed';
                    if ($stepStatus === 'failed') {
                        $hasFailures = true;
                    }

                    $taskService->logStep($task, $run, [
                        'step' => $stepNumber,
                        'status' => $stepStatus,
                        'observation' => 'OBSERVATION: '.json_encode($toolResult),
                        'tool_used' => $tool,
                        'tool_input' => $input,
                        'tool_output' => $toolResult,
                    ]);
                    continue;
                }

                $results[(string) $stepNumber] = [
                    'type' => 'note',
                    'message' => (string) ($stepDef['action'] ?? 'Step completed without a tool.'),
                ];

                $taskService->logStep($task, $run, [
                    'step' => $stepNumber,
                    'status' => 'completed',
                    'observation' => 'OBSERVATION: Step completed without tool invocation.',
                ]);
            }

            if ($hasFailures) {
                $taskService->failRun($task, $run, 'One or more task steps failed or were blocked by policy.');
                return;
            }

            $summary = trim((string) ($task->expected_output ?? 'Task completed.'));
            $taskService->completeRun($task, $run, $results, $summary);
        } catch (\Throwable $e) {
            $taskService->logStep($task, $run, [
                'step' => 999,
                'status' => 'failed',
                'observation' => 'OBSERVATION: '.$e->getMessage(),
            ]);
            $taskService->failRun($task, $run, $e->getMessage());
        }
    }
}
