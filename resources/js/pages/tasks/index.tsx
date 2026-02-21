import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

type TaskListItem = {
    id: string;
    title: string;
    description: string | null;
    status: 'pending' | 'queued' | 'running' | 'completed' | 'failed' | 'cancelled' | string;
    schedule_type: 'immediate' | 'one_time' | 'recurring' | 'event_triggered' | string;
    cron_human: string | null;
    next_run_at: string | null;
    last_run_at: string | null;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type Props = {
    tasks: {
        data: TaskListItem[];
        links: PaginationLink[];
    };
    filter: {
        status?: string;
        type?: string;
    };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tasks', href: '/tasks' },
];

const STATUS_COLORS: Record<string, string> = {
    pending: 'bg-gray-100 text-gray-700',
    queued: 'bg-yellow-100 text-yellow-700',
    running: 'bg-blue-100 text-blue-700 animate-pulse',
    completed: 'bg-green-100 text-green-700',
    failed: 'bg-red-100 text-red-700',
    cancelled: 'bg-gray-100 text-gray-500',
};

const SCHEDULE_ICONS: Record<string, string> = {
    immediate: '⚡',
    one_time: '🕐',
    recurring: '🔄',
    event_triggered: '🎯',
};

export default function TasksIndex({ tasks, filter }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tasks" />

            <div className="mx-auto max-w-5xl px-4 py-8">
                <div className="mb-6 flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900">Background Tasks</h1>
                        <p className="mt-1 text-sm text-gray-500">Tasks created from your AI assistant</p>
                    </div>
                    <div className="flex gap-2">
                        {['all', 'scheduled', 'queued', 'running', 'completed', 'failed'].map((s) => (
                            <button
                                key={s}
                                onClick={() => router.get('/tasks', { status: s === 'all' ? undefined : s })}
                                className={`rounded-full border px-3 py-1.5 text-sm capitalize transition ${
                                    (filter.status ?? 'all') === s
                                        ? 'border-gray-900 bg-gray-900 text-white'
                                        : 'border-gray-200 bg-white text-gray-600 hover:border-gray-400'
                                }`}
                            >
                                {s}
                            </button>
                        ))}
                    </div>
                </div>

                <div className="space-y-3">
                    {tasks.data.length === 0 && (
                        <div className="py-16 text-center text-gray-400">
                            <p className="mb-2 text-4xl">🤖</p>
                            <p>No tasks yet. Ask the AI to do something in the background.</p>
                            <Link href="/chat" className="mt-2 inline-block text-blue-600 underline">
                                Go to Chat →
                            </Link>
                        </div>
                    )}

                    {tasks.data.map((task) => (
                        <Link
                            key={task.id}
                            href={`/tasks/${task.id}`}
                            className="block rounded-xl border border-gray-200 bg-white p-4 transition hover:border-gray-400 hover:shadow-sm"
                        >
                            <div className="flex items-start justify-between gap-4">
                                <div className="min-w-0 flex-1">
                                    <div className="mb-1 flex items-center gap-2">
                                        <span className="text-lg">{SCHEDULE_ICONS[task.schedule_type] ?? '🗂'}</span>
                                        <h2 className="truncate font-semibold text-gray-900">{task.title}</h2>
                                    </div>
                                    {task.description && (
                                        <p className="truncate text-sm text-gray-500">{task.description}</p>
                                    )}
                                    <div className="mt-2 flex items-center gap-3 text-xs text-gray-400">
                                        <span>{task.cron_human ?? task.schedule_type}</span>
                                        {task.next_run_at && (
                                            <span>Next: {new Date(task.next_run_at).toLocaleString()}</span>
                                        )}
                                        {task.last_run_at && (
                                            <span>Last run: {new Date(task.last_run_at).toLocaleString()}</span>
                                        )}
                                    </div>
                                </div>
                                <span className={`rounded-full px-2.5 py-1 text-xs font-medium ${STATUS_COLORS[task.status] ?? 'bg-gray-100 text-gray-700'}`}>
                                    {task.status}
                                </span>
                            </div>
                        </Link>
                    ))}
                </div>

                <div className="mt-6 flex justify-center gap-2">
                    {tasks.links.map((link, i) => (
                        <Link
                            key={i}
                            href={link.url ?? '#'}
                            className={`rounded border px-3 py-1.5 text-sm ${
                                link.active ? 'bg-gray-900 text-white' : 'text-gray-600'
                            } ${link.url ? '' : 'pointer-events-none opacity-40'}`}
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
