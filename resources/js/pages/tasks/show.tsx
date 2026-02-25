import { Head, router } from '@inertiajs/react';
import {
    Activity,
    CalendarClock,
    CheckCircle2,
    CircleDot,
    PlayCircle,
    RefreshCw,
    StopCircle,
    Timer,
    TriangleAlert,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

type JsonObject = Record<string, unknown>;

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
    completed: 'bg-emerald-500',
    failed: 'bg-red-500',
    pending: 'bg-slate-300',
    queued: 'bg-amber-400',
    cancelled: 'bg-slate-400',
};

const STATUS_BADGE: Record<string, string> = {
    running: 'bg-blue-100 text-blue-700',
    completed: 'bg-emerald-100 text-emerald-700',
    failed: 'bg-red-100 text-red-700',
    pending: 'bg-slate-100 text-slate-700',
    queued: 'bg-amber-100 text-amber-700',
    cancelled: 'bg-slate-100 text-slate-600',
};

const SCHEDULE_LABELS: Record<string, string> = {
    immediate: 'Immediate',
    one_time: 'One-time',
    recurring: 'Recurring',
    event_triggered: 'Event-triggered',
};

function stripLabel(value: string | null, label: 'THOUGHT' | 'ACTION' | 'OBSERVATION'): string | null {
    if (!value) return null;
    return value.replace(new RegExp(`^${label}:\\s*`, 'i'), '').trim();
}

function toNumber(value: unknown): number | null {
    if (typeof value === 'number' && Number.isFinite(value)) return value;
    if (typeof value === 'string') {
        const parsed = Number(value);
        return Number.isFinite(parsed) ? parsed : null;
    }
    return null;
}

function formatNumber(value: unknown): string | null {
    const num = toNumber(value);
    if (num === null) return null;
    return new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 }).format(num);
}

function parseObservationPayload(observation: string | null): JsonObject | null {
    const cleaned = stripLabel(observation, 'OBSERVATION');
    if (!cleaned || (!cleaned.startsWith('{') && !cleaned.startsWith('['))) return null;
    try {
        const parsed = JSON.parse(cleaned);
        return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? (parsed as JsonObject) : null;
    } catch {
        return null;
    }
}

function getExecutionStatus(payload: JsonObject): string | null {
    const risk = (payload._policy as JsonObject | undefined)?.risk;
    const criticOk = (payload._critic as JsonObject | undefined)?.ok;
    if (risk === 'high') return 'blocked';
    if (criticOk === false) return 'needs_attention';
    return null;
}

function summarizeObservation(observation: string | null): { summary: string | null; raw: string | null; status: string | null } {
    const cleaned = stripLabel(observation, 'OBSERVATION');
    if (!cleaned) return { summary: null, raw: null, status: null };

    const payload = parseObservationPayload(observation);
    if (!payload) {
        return {
            summary: cleaned.length > 220 ? `${cleaned.slice(0, 220)}...` : cleaned,
            raw: cleaned,
            status: null,
        };
    }

    const type = typeof payload.type === 'string' ? payload.type : null;
    const message = typeof payload.message === 'string' ? payload.message : null;
    const status = getExecutionStatus(payload);

    if (type === 'orders_table') {
        const listed = Array.isArray(payload.orders) ? payload.orders.length : null;
        const total = formatNumber(payload.total);
        const page = formatNumber(payload.current_page);
        const pages = formatNumber(payload.last_page);
        const parts = [
            listed !== null ? `${listed} orders listed` : null,
            total ? `${total} total` : null,
            page && pages ? `page ${page}/${pages}` : null,
        ].filter(Boolean);

        return {
            summary: `Orders fetched: ${parts.join(', ')}.`,
            raw: cleaned,
            status,
        };
    }

    if (type === 'financial_report') {
        const totalOrders = formatNumber(payload.total_orders);
        const revenue = formatNumber(payload.total_revenue);
        const avg = formatNumber(payload.average_order_value);
        const reportStatus = typeof payload.status === 'string' ? payload.status : null;
        const parts = [
            reportStatus ? `status ${reportStatus}` : null,
            totalOrders ? `${totalOrders} orders` : null,
            revenue ? `revenue ${revenue}` : null,
            avg ? `avg ${avg}` : null,
        ].filter(Boolean);

        return {
            summary: `Financial report ready: ${parts.join(', ')}.`,
            raw: cleaned,
            status,
        };
    }

    if (type === 'policy_blocked') {
        const tool = typeof payload.tool === 'string' ? payload.tool : 'tool';
        const reason = message ?? 'Explicit confirmation is required.';
        return {
            summary: `${tool} blocked by policy. ${reason}`,
            raw: cleaned,
            status: 'blocked',
        };
    }

    if (type === 'error') {
        return {
            summary: message ? `Error: ${message}` : 'Error returned from tool execution.',
            raw: cleaned,
            status: 'needs_attention',
        };
    }

    return {
        summary: message ? `${type ?? 'Result'}: ${message}` : `Result type: ${type ?? 'unknown'}`,
        raw: cleaned,
        status,
    };
}

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

    useEffect(() => {
        return router.on('before', () => {
            if (!esRef.current) return;
            esRef.current.close();
            esRef.current = null;
        });
    }, []);

    const latestRun = runs[0];
    const allLogs = liveLogs.length > 0 ? liveLogs : (latestRun?.logs ?? []);

    const groupedSteps = useMemo(() => {
        const sorted = [...allLogs].sort((a, b) => {
            const stepDiff = (a.step || 0) - (b.step || 0);
            if (stepDiff !== 0) return stepDiff;
            const aTime = a.logged_at ? new Date(a.logged_at).getTime() : 0;
            const bTime = b.logged_at ? new Date(b.logged_at).getTime() : 0;
            return aTime - bTime;
        });

        const groups = new Map<number, TaskLog[]>();
        sorted.forEach((log, idx) => {
            const step = log.step || idx + 1;
            const existing = groups.get(step) ?? [];
            existing.push(log);
            groups.set(step, existing);
        });

        return Array.from(groups.entries())
            .map(([step, logs]) => {
                const thought = logs.map((log) => stripLabel(log.thought, 'THOUGHT')).find(Boolean) ?? null;
                const action = logs.map((log) => stripLabel(log.action, 'ACTION')).find(Boolean) ?? null;
                const observationLog = [...logs].reverse().find((log) => !!log.observation) ?? null;
                const observation = summarizeObservation(observationLog?.observation ?? null);
                const toolUsed = logs.map((log) => log.tool_used).find(Boolean) ?? null;
                const loggedAt = [...logs].reverse().find((log) => !!log.logged_at)?.logged_at ?? null;
                const status = [...logs].reverse().find((log) => !!log.status)?.status ?? 'pending';

                return {
                    step,
                    logs,
                    thought,
                    action,
                    observation,
                    toolUsed,
                    loggedAt,
                    status,
                };
            })
            .sort((a, b) => a.step - b.step);
    }, [allLogs]);

    const timelineStats = useMemo(() => {
        return {
            totalSteps: groupedSteps.length,
            totalEntries: allLogs.length,
            withTools: allLogs.filter((log) => !!log.tool_used).length,
            observations: allLogs.filter((log) => !!log.observation).length,
        };
    }, [allLogs, groupedSteps.length]);

    const statusClass = STATUS_BADGE[liveStatus] ?? 'bg-slate-100 text-slate-700';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={task.title} />

            <div className="h-[calc(100svh-6rem)] w-full overflow-y-auto px-4 py-4">
                <div className="mb-6 rounded-xl border border-gray-200 bg-white p-4">
                    <div className="mb-2 flex flex-wrap items-center gap-2">
                        <h1 className="text-2xl font-bold text-gray-900">{task.title}</h1>
                        <span className={`rounded-full px-2.5 py-1 text-xs font-medium ${statusClass}`}>
                            {liveStatus}
                        </span>
                    </div>

                    {task.description && <p className="text-sm text-gray-500">{task.description}</p>}

                    <div className="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="rounded-lg border border-gray-200 bg-gray-50 p-2">
                            <p className="mb-1 inline-flex items-center gap-1 text-[11px] uppercase tracking-wide text-gray-500">
                                <Timer className="h-3.5 w-3.5" />
                                Schedule
                            </p>
                            <p className="text-xs font-medium text-gray-800">
                                {SCHEDULE_LABELS[task.schedule_type] ?? task.schedule_type}
                            </p>
                        </div>
                        <div className="rounded-lg border border-gray-200 bg-gray-50 p-2">
                            <p className="mb-1 inline-flex items-center gap-1 text-[11px] uppercase tracking-wide text-gray-500">
                                <CalendarClock className="h-3.5 w-3.5" />
                                Next Run
                            </p>
                            <p className="text-xs font-medium text-gray-800">
                                {task.next_run_at ? new Date(task.next_run_at).toLocaleString() : 'Not scheduled'}
                            </p>
                        </div>
                        <div className="rounded-lg border border-gray-200 bg-gray-50 p-2">
                            <p className="mb-1 inline-flex items-center gap-1 text-[11px] uppercase tracking-wide text-gray-500">
                                <Activity className="h-3.5 w-3.5" />
                                Priority
                            </p>
                            <p className="text-xs font-medium text-gray-800">{task.priority}</p>
                        </div>
                        <div className="rounded-lg border border-gray-200 bg-gray-50 p-2">
                            <p className="mb-1 inline-flex items-center gap-1 text-[11px] uppercase tracking-wide text-gray-500">
                                <CircleDot className="h-3.5 w-3.5" />
                                Run At
                            </p>
                            <p className="text-xs font-medium text-gray-800">
                                {task.run_at ? new Date(task.run_at).toLocaleString() : 'Not set'}
                            </p>
                        </div>
                    </div>

                    <div className="mt-4 flex flex-wrap gap-2">
                        {['running', 'queued', 'pending'].includes(liveStatus) && (
                            <button
                                onClick={() => router.post(`/tasks/${task.id}/cancel`)}
                                className="inline-flex items-center gap-1.5 rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-sm text-red-700 hover:bg-red-100"
                            >
                                <StopCircle className="h-4 w-4" />
                                Cancel Task
                            </button>
                        )}
                        {['failed', 'completed', 'cancelled'].includes(liveStatus) && (
                            <button
                                onClick={() => router.post(`/tasks/${task.id}/retry`)}
                                className="inline-flex items-center gap-1.5 rounded-lg border border-blue-200 bg-blue-50 px-3 py-1.5 text-sm text-blue-700 hover:bg-blue-100"
                            >
                                <RefreshCw className="h-4 w-4" />
                                Re-run
                            </button>
                        )}
                    </div>
                </div>

                <div className="mb-6 rounded-xl border border-gray-200 bg-white p-4">
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <h2 className="font-semibold text-gray-900">Execution Timeline</h2>
                        <div className="flex flex-wrap items-center gap-2 text-xs text-gray-500">
                            <span className="rounded-md bg-gray-100 px-2 py-1">
                                Steps: {timelineStats.totalSteps}
                            </span>
                            <span className="rounded-md bg-gray-100 px-2 py-1">
                                Entries: {timelineStats.totalEntries}
                            </span>
                            <span className="rounded-md bg-gray-100 px-2 py-1">
                                Tools used: {timelineStats.withTools}
                            </span>
                            <span className="rounded-md bg-gray-100 px-2 py-1">
                                Observations: {timelineStats.observations}
                            </span>
                        </div>
                    </div>

                    {allLogs.length === 0 && liveStatus === 'pending' && (
                        <p className="inline-flex items-center gap-1 text-sm text-gray-500">
                            <PlayCircle className="h-4 w-4" />
                            Waiting to start...
                        </p>
                    )}

                    <div className="relative">
                        {groupedSteps.map((stepGroup, idx) => (
                            <div key={stepGroup.step} className="mb-5 flex gap-3">
                                <div className="flex flex-col items-center">
                                    <div className={`mt-1 h-3 w-3 rounded-full ${STATUS_DOT[stepGroup.status] ?? 'bg-slate-300'}`} />
                                    {idx < groupedSteps.length - 1 && <div className="mt-1 w-0.5 flex-1 bg-gray-200" />}
                                </div>

                                <div className="flex-1 rounded-lg border border-gray-200 bg-gray-50 p-3">
                                    <div className="mb-2 flex flex-wrap items-center gap-2">
                                        <span className="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                            Step {stepGroup.step}
                                        </span>
                                        {stepGroup.toolUsed && (
                                            <span className="rounded bg-violet-100 px-2 py-0.5 font-mono text-xs text-violet-700">
                                                {stepGroup.toolUsed}
                                            </span>
                                        )}
                                        <span className="ml-auto text-xs text-gray-400">
                                            {stepGroup.loggedAt ? new Date(stepGroup.loggedAt).toLocaleTimeString() : ''}
                                        </span>
                                    </div>

                                    <div className="space-y-2 text-sm text-gray-700">
                                        {stepGroup.thought && (
                                            <div>
                                                <p className="text-xs font-semibold text-indigo-600">Thought</p>
                                                <p>{stepGroup.thought}</p>
                                            </div>
                                        )}
                                        {stepGroup.action && (
                                            <div>
                                                <p className="text-xs font-semibold text-blue-600">Action</p>
                                                <p>{stepGroup.action}</p>
                                            </div>
                                        )}
                                        {stepGroup.observation.summary && (
                                            <div>
                                                <p className="text-xs font-semibold text-emerald-600">Observation</p>
                                                <p
                                                    className={
                                                        stepGroup.observation.status === 'blocked'
                                                            ? 'text-amber-700'
                                                            : stepGroup.observation.status === 'needs_attention'
                                                              ? 'text-red-700'
                                                              : ''
                                                    }
                                                >
                                                    {stepGroup.observation.summary}
                                                </p>
                                                {stepGroup.observation.raw && (
                                                    <details className="mt-2">
                                                        <summary className="cursor-pointer text-xs text-gray-500 hover:text-gray-700">
                                                            View raw observation
                                                        </summary>
                                                        <pre className="mt-2 max-h-52 overflow-auto rounded-md bg-gray-900 p-2 text-xs text-emerald-300">
                                                            {stepGroup.observation.raw}
                                                        </pre>
                                                    </details>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>
                        ))}

                        {liveStatus === 'running' && (
                            <p className="inline-flex items-center gap-1 text-sm text-blue-600 animate-pulse">
                                <Activity className="h-4 w-4" />
                                Processing...
                            </p>
                        )}
                    </div>
                </div>

                {latestRun?.output && (
                    <div className="mb-6 rounded-xl border border-gray-200 bg-white p-4">
                        <h2 className="mb-3 font-semibold text-gray-900">Output</h2>
                        <div className="max-h-80 overflow-auto rounded-xl bg-gray-950 p-4 font-mono text-sm text-green-400">
                            <pre>{JSON.stringify(latestRun.output, null, 2)}</pre>
                        </div>
                        {latestRun.summary && (
                            <p className="mt-3 inline-flex items-start gap-2 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-gray-700">
                                <CheckCircle2 className="mt-0.5 h-4 w-4 text-emerald-600" />
                                <span>{latestRun.summary}</span>
                            </p>
                        )}
                    </div>
                )}

                {runs.length > 1 && (
                    <div className="rounded-xl border border-gray-200 bg-white p-4">
                        <h2 className="mb-3 font-semibold text-gray-900">Run History</h2>
                        <div className="space-y-2">
                            {runs.map((run) => (
                                <div key={run.id} className="rounded-lg border border-gray-100 p-3">
                                    <div className="mb-1 flex flex-wrap items-center gap-2">
                                        <span className="text-sm text-gray-600">
                                            {new Date(run.started_at).toLocaleString()}
                                        </span>
                                        <span
                                            className={`rounded-full px-2 py-0.5 text-xs ${
                                                run.status === 'completed'
                                                    ? 'bg-emerald-100 text-emerald-700'
                                                    : run.status === 'failed'
                                                      ? 'bg-red-100 text-red-700'
                                                      : 'bg-slate-100 text-slate-700'
                                            }`}
                                        >
                                            {run.status}
                                        </span>
                                    </div>
                                    {run.summary ? (
                                        <p className="text-xs text-gray-500">{run.summary}</p>
                                    ) : (
                                        <p className="inline-flex items-center gap-1 text-xs text-amber-600">
                                            <TriangleAlert className="h-3.5 w-3.5" />
                                            No summary available.
                                        </p>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
