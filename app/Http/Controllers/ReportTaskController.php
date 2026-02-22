<?php

namespace App\Http\Controllers;

use App\Services\ReportTaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportTaskController extends Controller
{
    public function show(string $taskId, ReportTaskService $service): JsonResponse
    {
        $task = $service->get($taskId, (int) auth()->id());
        if ($task === null) {
            return response()->json(['message' => 'Task not found.'], 404);
        }

        return response()->json([
            ...$task,
            'type' => 'task_workflow',
            'confirm_url' => route('report-tasks.confirm', ['taskId' => $taskId]),
        ]);
    }

    public function confirm(Request $request, string $taskId, ReportTaskService $service): JsonResponse
    {
        $task = $service->confirm($taskId, (int) $request->user()->id);
        if ($task === null) {
            return response()->json(['message' => 'Task not found.'], 404);
        }

        return response()->json([
            ...$task,
            'type' => 'task_workflow',
            'confirm_url' => route('report-tasks.confirm', ['taskId' => $taskId]),
        ]);
    }
}
