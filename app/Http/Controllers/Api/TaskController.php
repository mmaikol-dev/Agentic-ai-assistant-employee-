<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function __construct(private readonly TaskService $taskService) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'schedule_type' => ['required', 'in:immediate,one_time,recurring,event_triggered'],
            'run_at' => ['nullable', 'date'],
            'cron_expression' => ['nullable', 'string'],
            'cron_human' => ['nullable', 'string'],
            'event_condition' => ['nullable', 'string'],
            'timezone' => ['nullable', 'string'],
            'priority' => ['nullable', 'in:low,normal,high'],
            'execution_plan' => ['nullable', 'array'],
            'execution_plan.*.step' => ['nullable', 'integer'],
            'execution_plan.*.action' => ['nullable', 'string'],
            'execution_plan.*.tool' => ['nullable', 'string'],
            'execution_plan.*.tool_input' => ['nullable', 'array'],
            'execution_plan.*.input_summary' => ['nullable', 'string'],
            'execution_plan.*.depends_on' => ['nullable', 'array'],
            'expected_output' => ['nullable', 'string'],
            'original_user_request' => ['nullable', 'string'],
            'chat_message_id' => ['nullable', 'string'],
        ]);

        $task = $this->taskService->createFromToolCall(
            $validated,
            (int) $request->user()->id,
            $validated['chat_message_id'] ?? null,
        );

        return response()->json([
            'task' => $task,
            'message' => "Task '{$task->title}' created. Track it at /tasks/{$task->id}",
        ], 201);
    }

    public function logs(Request $request, string $taskId): JsonResponse
    {
        $task = Task::query()
            ->where('id', $taskId)
            ->where('user_id', (int) $request->user()->id)
            ->firstOrFail();

        return response()->json(
            $task->logs()->orderBy('logged_at')->orderBy('created_at')->get(),
        );
    }
}
