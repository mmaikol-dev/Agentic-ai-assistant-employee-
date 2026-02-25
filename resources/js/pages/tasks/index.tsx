import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    CalendarClock,
    CheckCircle2,
    Clock3,
    ListChecks,
    RotateCcw,
    XCircle,
} from 'lucide-react';
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

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Tasks', href: '/tasks' }];

const STATUS_COLORS: Record<string, string> = {
    pending: 'bg-slate-100 text-slate-700',
    queued: 'bg-amber-100 text-amber-700',
    running: 'bg-blue-100 text-blue-700',
    completed: 'bg-emerald-100 text-emerald-700',
    failed: 'bg-red-100 text-red-700',
    cancelled: 'bg-slate-100 text-slate-500',
};

const SCHEDULE_LABELS: Record<string, string> = {
    immediate: 'Immediate',
    one_time: 'One-time',
    recurring: 'Recurring',
    event_triggered: 'Event-triggered',
};

export default function TasksIndex({ tasks, filter }: Props) {
    const allTasks = tasks.data;
    const statusCounts = allTasks.reduce<Record<string, number>>((acc, task) => {
        acc[task.status] = (acc[task.status] ?? 0) + 1;
        return acc;
    }, {});

    const scheduledCount = (statusCounts.pending ?? 0) + (statusCounts.queued ?? 0);
    const selectedFilter = filter.status ?? 'all';

    const filterOptions = [
        { key: 'all', label: 'All', count: allTasks.length },
        { key: 'scheduled', label: 'Scheduled', count: scheduledCount },
        { key: 'running', label: 'Running', count: statusCounts.running ?? 0 },
        { key: 'completed', label: 'Completed', count: statusCounts.completed ?? 0 },
        { key: 'failed', label: 'Failed', count: statusCounts.failed ?? 0 },
    ];

    const statCards = [
        {
            label: 'Total Tasks',
            value: allTasks.length,
            icon: ListChecks,
            tone: 'text-slate-700 bg-slate-100',
        },
        {
            label: 'Running',
            value: statusCounts.running ?? 0,
            icon: Activity,
            tone: 'text-blue-700 bg-blue-100',
        },
        {
            label: 'Completed',
            value: statusCounts.completed ?? 0,
            icon: CheckCircle2,
            tone: 'text-emerald-700 bg-emerald-100',
        },
        {
            label: 'Failed',
            value: statusCounts.failed ?? 0,
            icon: XCircle,
            tone: 'text-red-700 bg-red-100',
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tasks" />

            <div className="h-[calc(100svh-6rem)] w-full px-4 py-4">
                <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900">Background Tasks</h1>
                        <p className="mt-1 text-sm text-gray-500">
                            Monitor execution, schedules, and outcomes from your assistant jobs.
                        </p>
                    </div>
                    <Link
                        href="/chat"
                        className="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 hover:border-gray-400"
                    >
                        <RotateCcw className="h-4 w-4" />
                        Back to Chat
                    </Link>
                </div>

                <div className="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    {statCards.map((card) => {
                        const Icon = card.icon;
                        return (
                            <div key={card.label} className="rounded-xl border border-gray-200 bg-white p-3">
                                <div className="flex items-center justify-between">
                                    <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                        {card.label}
                                    </p>
                                    <span className={`rounded-md p-1.5 ${card.tone}`}>
                                        <Icon className="h-4 w-4" />
                                    </span>
                                </div>
                                <p className="mt-2 text-2xl font-semibold text-gray-900">{card.value}</p>
                            </div>
                        );
                    })}
                </div>

                <div className="mb-5 flex flex-wrap gap-2">
                    {filterOptions.map((option) => (
                        <button
                            key={option.key}
                            onClick={() =>
                                router.get('/tasks', {
                                    status: option.key === 'all' ? undefined : option.key,
                                })
                            }
                            className={`inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm transition ${
                                selectedFilter === option.key
                                    ? 'border-gray-900 bg-gray-900 text-white'
                                    : 'border-gray-200 bg-white text-gray-600 hover:border-gray-400'
                            }`}
                        >
                            {option.label}
                            <span
                                className={`rounded-full px-1.5 text-xs ${
                                    selectedFilter === option.key
                                        ? 'bg-white/20 text-white'
                                        : 'bg-gray-100 text-gray-600'
                                }`}
                            >
                                {option.count}
                            </span>
                        </button>
                    ))}
                </div>

                <div className="h-[calc(100%-15rem)] min-h-0 space-y-3 overflow-y-auto pr-1">
                    {tasks.data.length === 0 && (
                        <div className="rounded-xl border border-dashed border-gray-300 bg-white py-16 text-center text-gray-500">
                            <p className="mb-2 text-4xl">🗂</p>
                            <p className="font-medium text-gray-700">No tasks found for this filter.</p>
                            <Link href="/chat" className="mt-2 inline-block text-blue-600 underline">
                                Create one from chat
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
                                        <h2 className="truncate font-semibold text-gray-900">{task.title}</h2>
                                        <span
                                            className={`rounded-full px-2 py-0.5 text-[11px] font-medium ${
                                                STATUS_COLORS[task.status] ?? 'bg-gray-100 text-gray-700'
                                            } ${task.status === 'running' ? 'animate-pulse' : ''}`}
                                        >
                                            {task.status}
                                        </span>
                                    </div>

                                    {task.description && (
                                        <p className="line-clamp-2 text-sm text-gray-500">{task.description}</p>
                                    )}

                                    <div className="mt-3 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                                        <span className="inline-flex items-center gap-1 rounded-md bg-gray-100 px-2 py-1">
                                            <Clock3 className="h-3.5 w-3.5" />
                                            {SCHEDULE_LABELS[task.schedule_type] ?? task.schedule_type}
                                        </span>
                                        <span className="inline-flex items-center gap-1 rounded-md bg-gray-100 px-2 py-1">
                                            <CalendarClock className="h-3.5 w-3.5" />
                                            {task.cron_human ?? 'No cron text'}
                                        </span>
                                        {task.next_run_at && (
                                            <span className="rounded-md bg-amber-50 px-2 py-1 text-amber-700">
                                                Next: {new Date(task.next_run_at).toLocaleString()}
                                            </span>
                                        )}
                                        {task.last_run_at && (
                                            <span className="rounded-md bg-slate-100 px-2 py-1">
                                                Last: {new Date(task.last_run_at).toLocaleString()}
                                            </span>
                                        )}
                                    </div>
                                </div>
                            </div>
                        </Link>
                    ))}
                </div>

                <div className="mt-6 flex flex-wrap items-center justify-center gap-2">
                    {tasks.links.map((link, i) => (
                        <Link
                            key={i}
                            href={link.url ?? '#'}
                            className={`rounded-md border px-3 py-1.5 text-sm ${
                                link.active
                                    ? 'border-gray-900 bg-gray-900 text-white'
                                    : 'border-gray-200 bg-white text-gray-600'
                            } ${link.url ? '' : 'pointer-events-none opacity-40'}`}
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
