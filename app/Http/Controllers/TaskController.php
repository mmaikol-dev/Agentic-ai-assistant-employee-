<?php

namespace App\Http\Controllers;

use App\Jobs\RunTaskJob;
use App\Models\Task;
use App\Models\TaskLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaskController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString();

        $tasks = Task::query()
            ->where('user_id', (int) $request->user()->id)
            ->with('latestRun')
            ->when($status !== '', function ($q) use ($status) {
                if ($status === 'scheduled') {
                    $q->whereIn('status', ['pending', 'queued']);
                    return;
                }

                $q->where('status', $status);
            })
            ->when($request->string('type')->toString() !== '', fn ($q) => $q->where('schedule_type', $request->string('type')->toString()))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('tasks/index', [
            'tasks' => $tasks,
            'filter' => $request->only('status', 'type'),
        ]);
    }

    public function show(Request $request, Task $task): Response
    {
        abort_unless((int) $task->user_id === (int) $request->user()->id, 403);

        return Inertia::render('tasks/show', [
            'task' => $task,
            'runs' => $task->runs()
                ->with('logs')
                ->orderByDesc('started_at')
                ->get(),
        ]);
    }

    public function cancel(Request $request, Task $task): RedirectResponse
    {
        abort_unless((int) $task->user_id === (int) $request->user()->id, 403);
        $task->update(['status' => 'cancelled']);

        return back()->with('success', 'Task cancelled.');
    }

    public function retry(Request $request, Task $task): RedirectResponse
    {
        abort_unless((int) $task->user_id === (int) $request->user()->id, 403);
        RunTaskJob::dispatch($task->id);
        $task->update(['status' => 'queued']);

        return back()->with('success', 'Task queued for retry.');
    }

    public function stream(Request $request, Task $task): StreamedResponse
    {
        abort_unless((int) $task->user_id === (int) $request->user()->id, 403);
        if ($request->hasSession()) {
            // Release the session lock so other authenticated page requests are not blocked.
            $request->session()->save();
            if (\function_exists('session_write_close')) {
                @\session_write_close();
            }
        }

        return response()->stream(function () use ($task): void {
            $cursorTime = now()->subSecond();
            $maxLoops = 120;
            $loop = 0;

            while ($loop < $maxLoops) {
                $logs = TaskLog::query()
                    ->where('task_id', $task->id)
                    ->where('logged_at', '>', $cursorTime)
                    ->orderBy('logged_at')
                    ->orderBy('created_at')
                    ->get();

                foreach ($logs as $log) {
                    echo 'data: '.json_encode($log)."\n\n";
                    @ob_flush();
                    flush();
                    $cursorTime = $log->logged_at ?? now();
                }

                $task->refresh();
                if (in_array($task->status, ['completed', 'failed', 'cancelled'], true)) {
                    echo 'data: '.json_encode(['type' => 'done', 'status' => $task->status])."\n\n";
                    @ob_flush();
                    flush();
                    break;
                }

                sleep(1);
                $loop++;
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
