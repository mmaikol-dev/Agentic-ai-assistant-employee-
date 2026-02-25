import { Head } from '@inertiajs/react';
import {
    BarChart3,
    Box,
    MessageSquare,
    PencilLine,
    Search,
    ShieldAlert,
    Wrench,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

type ToolRecord = {
    key: string;
    label: string;
    path: string;
    content: string;
    class_name: string;
    description: string;
    category: string;
    icon_key: string;
    risk_level: string;
    method_count: number;
    line_count: number;
    source_explained: {
        summary: string;
        capabilities: string[];
        flow: string[];
        methods: Array<{
            name: string;
            visibility: string;
            intent: string;
        }>;
    };
    updated_at: string;
};

type Props = {
    tools: ToolRecord[];
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Tools', href: '/tools' }];

const iconByKey = {
    chart: BarChart3,
    message: MessageSquare,
    edit: PencilLine,
    search: Search,
    wrench: Wrench,
    box: Box,
} as const;

const categoryClassByName: Record<string, string> = {
    reporting: 'bg-blue-100 text-blue-700',
    messaging: 'bg-emerald-100 text-emerald-700',
    mutation: 'bg-amber-100 text-amber-700',
    query: 'bg-violet-100 text-violet-700',
    platform: 'bg-cyan-100 text-cyan-700',
    general: 'bg-slate-100 text-slate-700',
};

const riskClassByName: Record<string, string> = {
    high: 'bg-red-100 text-red-700',
    medium: 'bg-amber-100 text-amber-700',
    low: 'bg-emerald-100 text-emerald-700',
};

export default function ToolsIndex({ tools }: Props) {
    const [query, setQuery] = useState('');
    const [selectedKey, setSelectedKey] = useState<string>(tools[0]?.key ?? '');

    const filteredTools = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (q === '') return tools;
        return tools.filter((tool) =>
            [tool.label, tool.key, tool.path, tool.category, tool.description].some((value) =>
                value.toLowerCase().includes(q),
            ),
        );
    }, [query, tools]);

    const selectedTool = useMemo(
        () => tools.find((tool) => tool.key === selectedKey) ?? null,
        [tools, selectedKey],
    );

    useEffect(() => {
        if (!selectedTool && filteredTools[0]) {
            setSelectedKey(filteredTools[0].key);
        }
    }, [filteredTools, selectedTool]);

    const selectTool = (tool: ToolRecord) => {
        setSelectedKey(tool.key);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tools" />

            <div className="h-[calc(100svh-6rem)] w-full px-4 py-4">
                <div className="mb-6">
                    <h1 className="text-2xl font-semibold text-gray-900">Tools</h1>
                    <p className="mt-1 text-sm text-gray-500">
                        Manage MCP tools like a platform catalog with code, metadata, and risk profile.
                    </p>
                </div>

                <div className="grid h-[calc(100%-4.5rem)] min-h-0 grid-cols-[300px_1fr] gap-4">
                    <aside className="flex min-h-0 flex-col rounded-xl border border-gray-200 bg-white p-3">
                        <input
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Search tools..."
                            className="mb-3 w-full rounded-md border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none"
                        />

                        <div className="min-h-0 flex-1 space-y-1 overflow-y-auto pr-1">
                            {filteredTools.length === 0 && (
                                <p className="rounded-md border border-dashed border-gray-200 p-3 text-xs text-gray-500">
                                    No tools matched your search.
                                </p>
                            )}

                            {filteredTools.map((tool) => (
                                <button
                                    key={tool.key}
                                    type="button"
                                    onClick={() => selectTool(tool)}
                                    className={`w-full rounded-lg border px-3 py-2 text-left transition ${
                                        tool.key === selectedKey
                                            ? 'border-gray-900 bg-gray-900 text-white shadow-sm'
                                            : 'border-gray-200 bg-white text-gray-700 hover:border-gray-400'
                                    }`}
                                >
                                    <div className="mb-1 flex items-center gap-2">
                                        {(() => {
                                            const Icon = iconByKey[
                                                (tool.icon_key as keyof typeof iconByKey) ?? 'box'
                                            ] ?? Box;
                                            return <Icon className="h-3.5 w-3.5 shrink-0" />;
                                        })()}
                                        <p className="truncate text-sm font-medium">{tool.label}</p>
                                    </div>
                                    <p className={`line-clamp-2 text-[11px] ${
                                        tool.key === selectedKey ? 'text-gray-300' : 'text-gray-500'
                                    }`}>
                                        {tool.description}
                                    </p>
                                    <div className="mt-2 flex flex-wrap items-center gap-1">
                                        <span className={`rounded-full px-1.5 py-0.5 text-[10px] ${
                                            categoryClassByName[tool.category] ?? categoryClassByName.general
                                        }`}>
                                            {tool.category}
                                        </span>
                                        <span className={`rounded-full px-1.5 py-0.5 text-[10px] ${
                                            riskClassByName[tool.risk_level] ?? riskClassByName.low
                                        }`}>
                                            {tool.risk_level} risk
                                        </span>
                                    </div>
                                </button>
                            ))}
                        </div>
                    </aside>

                    <section className="min-h-0 rounded-xl border border-gray-200 bg-white p-4">
                        {!selectedTool ? (
                            <p className="text-sm text-gray-500">Select a tool to view details.</p>
                        ) : (
                            <div className="flex h-full min-h-0 flex-col">
                                <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <h2 className="text-lg font-medium text-gray-900">{selectedTool.label}</h2>
                                        <p className="text-xs text-gray-500">{selectedTool.path}</p>
                                    </div>
                                    <span className="rounded-full bg-slate-100 px-2 py-1 text-xs text-slate-700">
                                        Read-only catalog
                                    </span>
                                </div>
                                <div className="mb-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                                    <div className="rounded-md border border-gray-200 bg-gray-50 p-2">
                                        <p className="text-[11px] text-gray-500">Class</p>
                                        <p className="truncate text-xs font-medium text-gray-800">{selectedTool.class_name}</p>
                                    </div>
                                    <div className="rounded-md border border-gray-200 bg-gray-50 p-2">
                                        <p className="text-[11px] text-gray-500">Category</p>
                                        <p className="text-xs font-medium text-gray-800">{selectedTool.category}</p>
                                    </div>
                                    <div className="rounded-md border border-gray-200 bg-gray-50 p-2">
                                        <p className="text-[11px] text-gray-500">Complexity</p>
                                        <p className="text-xs font-medium text-gray-800">{selectedTool.method_count} methods</p>
                                    </div>
                                    <div className="rounded-md border border-gray-200 bg-gray-50 p-2">
                                        <p className="text-[11px] text-gray-500">Footprint</p>
                                        <p className="text-xs font-medium text-gray-800">{selectedTool.line_count} lines</p>
                                    </div>
                                </div>
                                <div className="mb-3 flex items-start gap-2 rounded-md border border-slate-200 bg-slate-50 p-2">
                                    <ShieldAlert className="mt-0.5 h-4 w-4 shrink-0 text-slate-600" />
                                    <div>
                                        <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-700">
                                            Platform Description
                                        </p>
                                        <p className="text-xs text-slate-700">{selectedTool.description}</p>
                                    </div>
                                </div>

                                <div className="mb-3 rounded-lg border border-blue-200 bg-blue-50 p-3">
                                    <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-blue-700">
                                        🔍 Source Explained
                                    </p>
                                    <p className="text-sm text-blue-900">
                                        {selectedTool.source_explained.summary}
                                    </p>
                                </div>

                                <div className="mb-3">
                                    <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-600">
                                        ⚙️ Capabilities
                                    </p>
                                    <div className="flex flex-wrap gap-1.5">
                                        {selectedTool.source_explained.capabilities.map((capability) => (
                                            <span
                                                key={capability}
                                                className="rounded-full bg-white px-2 py-1 text-xs text-gray-700 ring-1 ring-gray-200"
                                            >
                                                {capability}
                                            </span>
                                        ))}
                                    </div>
                                </div>

                                <div className="mb-3 rounded-md border border-gray-200 bg-gray-50 p-3">
                                    <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-600">
                                        🚦 Execution Flow
                                    </p>
                                    <div className="space-y-1">
                                        {selectedTool.source_explained.flow.map((step, index) => (
                                            <p key={`${step}-${index}`} className="text-sm text-gray-700">
                                                {index + 1}. {step}
                                            </p>
                                        ))}
                                    </div>
                                </div>

                                <div className="min-h-0 flex-1 overflow-y-auto rounded-md border border-gray-200 bg-white p-3">
                                    <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-600">
                                        🧠 Method Intent Map
                                    </p>
                                    <div className="space-y-2">
                                        {selectedTool.source_explained.methods.length === 0 && (
                                            <p className="text-sm text-gray-500">No method metadata found.</p>
                                        )}
                                        {selectedTool.source_explained.methods.map((method) => (
                                            <div
                                                key={`${method.visibility}-${method.name}`}
                                                className="rounded-md border border-gray-100 bg-gray-50 p-2"
                                            >
                                                <div className="mb-1 flex items-center gap-2">
                                                    <span className="rounded bg-slate-200 px-1.5 py-0.5 text-[10px] font-medium text-slate-700">
                                                        {method.visibility}
                                                    </span>
                                                    <span className="font-mono text-xs text-gray-900">
                                                        {method.name}()
                                                    </span>
                                                </div>
                                                <p className="text-xs text-gray-700">{method.intent}</p>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                                <div className="mt-2 text-xs text-gray-500">
                                    Last updated: {new Date(selectedTool.updated_at).toLocaleString()}
                                </div>
                            </div>
                        )}
                    </section>
                </div>
            </div>
        </AppLayout>
    );
}
