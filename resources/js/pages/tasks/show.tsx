import { Head, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

type TaskLog = {
    id: string;
    step: number;
    status: string;
    thought: string | null;
    action: string | null;
    observation: string | null;
    tool_used: string | null;
    logged_at: string | null;
};

type TaskRun = {
    id: string;
    status: string;
    started_at: string;
    summary: string | null;
    output: Record<string, unknown> | null;
    logs: TaskLog[];
};

type Task = {
    id: string;
    title: string;
    description: string | null;
    status: string;
    schedule_type: string;
    run_at: string | null;
    next_run_at: string | null;
    cron_human: string | null;
    priority: string;
};

type Props = {
    task: Task;
    runs: TaskRun[];
};

const STATUS_DOT: Record<string, string> = {
    running: 'bg-blue-500 animate-pulse',
    completed: 'bg-green-500',
    failed: 'bg-red-500',
    pending: 'bg-gray-300',
    queued: 'bg-amber-400',
};

export default function TasksShow({ task, runs }: Props) {
    const [liveLogs, setLiveLogs] = useState<TaskLog[]>([]);
    const [liveStatus, setLiveStatus] = useState(task.status);
    const esRef = useRef<EventSource | null>(null);
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Tasks', href: '/tasks' },
        { title: task.title, href: `/tasks/${task.id}` },
    ];

    useEffect(() => {
        if (!['running', 'queued', 'pending'].includes(task.status)) return;

        const es = new EventSource(`/tasks/${task.id}/stream`);
        esRef.current = es;

        es.onmessage = (event) => {
            const data = JSON.parse(event.data);
            if (data.type === 'done') {
                setLiveStatus(data.status);
                es.close();
                return;
            }
            setLiveLogs((prev) => {
                const exists = prev.find((log) => log.id === data.id);
                return exists ? prev : [...prev, data];
            });
        };

        return () => es.close();
    }, [task.id, task.status]);

    const latestRun = runs[0];
    const allLogs = liveLogs.length > 0 ? liveLogs : (latestRun?.logs ?? []);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={task.title} />

            <div className="mx-auto max-w-3xl px-4 py-8">
                <div className="mb-8">
                    <div className="mb-1 flex items-center gap-3">
                        <h1 className="text-2xl font-bold text-gray-900">{task.title}</h1>
                        <span className={`rounded-full px-2.5 py-1 text-xs font-medium ${
                            liveStatus === 'completed'
                                ? 'bg-green-100 text-green-700'
                                : liveStatus === 'running'
                                    ? 'animate-pulse bg-blue-100 text-blue-700'
                                    : liveStatus === 'failed'
                                        ? 'bg-red-100 text-red-700'
                                        : 'bg-gray-100 text-gray-700'
                        }`}>{liveStatus}</span>
                    </div>

                    {task.description && <p className="text-gray-500">{task.description}</p>}

                    <div className="mt-3 flex flex-wrap gap-4 text-sm text-gray-500">
                        <span>📅 <strong>Schedule:</strong> {task.cron_human ?? task.schedule_type}</span>
                        {task.run_at && <span>🕐 <strong>Run at:</strong> {new Date(task.run_at).toLocaleString()}</span>}
                        {task.next_run_at && <span>⏭ <strong>Next:</strong> {new Date(task.next_run_at).toLocaleString()}</span>}
                        <span>⚡ <strong>Priority:</strong> {task.priority}</span>
                    </div>

                    <div className="mt-4 flex gap-2">
                        {['running', 'queued', 'pending'].includes(liveStatus) && (
                            <button
                                onClick={() => router.post(`/tasks/${task.id}/cancel`)}
                                className="rounded-lg border border-red-200 bg-red-50 px-4 py-1.5 text-sm text-red-700 hover:bg-red-100"
                            >
                                Cancel Task
                            </button>
                        )}
                        {['failed', 'completed', 'cancelled'].includes(liveStatus) && (
                            <button
                                onClick={() => router.post(`/tasks/${task.id}/retry`)}
                                className="rounded-lg border border-blue-200 bg-blue-50 px-4 py-1.5 text-sm text-blue-700 hover:bg-blue-100"
                            >
                                Re-run
                            </button>
                        )}
                    </div>
                </div>

                <div className="mb-8">
                    <h2 className="mb-4 font-semibold text-gray-900">⚙️ Execution Timeline</h2>
                    {allLogs.length === 0 && liveStatus === 'pending' && (
                        <p className="text-sm text-gray-400">Waiting to start...</p>
                    )}

                    <div className="relative">
                        {allLogs.map((log, idx) => (
                            <div key={log.id ?? idx} className="mb-6 flex gap-4">
                                <div className="flex flex-col items-center">
                                    <div className={`mt-1 h-3 w-3 flex-shrink-0 rounded-full ${STATUS_DOT[log.status] ?? 'bg-gray-300'}`} />
                                    {idx < allLogs.length - 1 && <div className="mt-1 w-0.5 flex-1 bg-gray-200" />}
                                </div>

                                <div className="flex-1 pb-2">
                                    <div className="mb-1 flex items-center gap-2">
                                        <span className="text-xs font-semibold uppercase tracking-wide text-gray-500">Step {log.step}</span>
                                        {log.tool_used && (
                                            <span className="rounded bg-purple-50 px-2 py-0.5 font-mono text-xs text-purple-700">
                                                {log.tool_used}
                                            </span>
                                        )}
                                        <span className="ml-auto text-xs text-gray-400">
                                            {log.logged_at ? new Date(log.logged_at).toLocaleTimeString() : ''}
                                        </span>
                                    </div>

                                    <div className="space-y-2 rounded-lg bg-gray-50 p-3 text-sm">
                                        {log.thought && (
                                            <div>
                                                <span className="text-xs font-semibold text-indigo-600">Thought</span>
                                                <p className="mt-0.5 text-gray-700">{log.thought}</p>
                                            </div>
                                        )}
                                        {log.action && (
                                            <div>
                                                <span className="text-xs font-semibold text-blue-600">Action</span>
                                                <p className="mt-0.5 text-gray-700">{log.action}</p>
                                            </div>
                                        )}
                                        {log.observation && (
                                            <div>
                                                <span className="text-xs font-semibold text-green-600">Observation</span>
                                                <p className="mt-0.5 text-gray-700">{log.observation}</p>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>
                        ))}

                        {liveStatus === 'running' && (
                            <div className="flex gap-4">
                                <div className="flex flex-col items-center">
                                    <div className="mt-1 h-3 w-3 rounded-full bg-blue-500 animate-pulse" />
                                </div>
                                <p className="py-1 text-sm text-blue-500 animate-pulse">Processing...</p>
                            </div>
                        )}
                    </div>
                </div>

                {latestRun?.output && (
                    <div className="mb-8">
                        <h2 className="mb-3 font-semibold text-gray-900">📋 Output</h2>
                        <div className="max-h-80 overflow-auto rounded-xl bg-gray-950 p-4 font-mono text-sm text-green-400">
                            <pre>{JSON.stringify(latestRun.output, null, 2)}</pre>
                        </div>
                        {latestRun.summary && (
                            <p className="mt-3 rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-gray-600">
                                ✅ {latestRun.summary}
                            </p>
                        )}
                    </div>
                )}

                {runs.length > 1 && (
                    <div>
                        <h2 className="mb-3 font-semibold text-gray-900">📜 Run History</h2>
                        <div className="space-y-2">
                            {runs.map((run) => (
                                <div key={run.id} className="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                                    <span className="text-sm text-gray-500">{new Date(run.started_at).toLocaleString()}</span>
                                    <span className={`rounded-full px-2 py-0.5 text-xs ${
                                        run.status === 'completed' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'
                                    }`}>
                                        {run.status}
                                    </span>
                                    <span className="text-xs text-gray-400">{run.summary?.slice(0, 60)}...</span>
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
