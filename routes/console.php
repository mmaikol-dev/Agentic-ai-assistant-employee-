<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\RunTaskJob;
use App\Models\Task;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

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
