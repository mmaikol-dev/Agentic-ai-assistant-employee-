import { Head, router } from '@inertiajs/react';
import {
    AlertCircle,
    CheckCircle2,
    X,
    ChevronLeft,
    ChevronRight,
    Download,
    Link,
    Loader2,
    Paperclip,
    Plus,
    Square,
    SendHorizontal,
    Smile,
    Sparkles,
    Wrench,
    PackagePlus,
    PackageCheck,
    Table2,
    Package,
    Gauge,
} from 'lucide-react';
import type { FormEvent, KeyboardEvent, ReactNode } from 'react';
import { useEffect, useMemo, useState } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { submitTaskToBackend, type TaskPayload } from '@/lib/task-parser';
import type { BreadcrumbItem } from '@/types';

// ── Types ────────────────────────────────────────────────────────────────────

type ChatRole = 'assistant' | 'user';

type OrderRow = Record<string, unknown>;

type ToolResult =
    | {
          type: 'orders_table';
          orders: OrderRow[];
          total: number;
          current_page: number;
          last_page: number;
          per_page: number;
      }
    | { type: 'order_detail'; order: OrderRow }
    | { type: 'order_created'; message: string; order: OrderRow }
    | { type: 'order_updated'; message: string; order: OrderRow }
    | {
          type: 'financial_report';
          merchant?: string | null;
          status: string;
          total_orders: number;
          total_revenue: number;
          average_order_value: number;
          date_range?: {
              earliest_order_date?: string | null;
              latest_order_date?: string | null;
          };
          product_breakdown?: Array<{
              product_name: string;
              order_count: number;
              total_revenue: number;
              average_price: number;
          }>;
          city_breakdown?: Array<{ city: string; order_count: number }>;
          listed_orders_count?: number;
          orders?: OrderRow[];
          excel_download_url?: string;
      }
    | {
          type: 'integration_requirements';
          integration_name: string;
          message: string;
          provider_options?: string[];
          questions?: string[];
          required_inputs?: string[];
      }
    | {
          type: 'integration_setup';
          integration_name: string;
          provider: string;
          message: string;
          files_created?: string[];
          required_env_keys?: string[];
          missing_env_keys?: string[];
          next_step?: string;
      }
    | {
          type: 'whatsapp_message_sent';
          to: string;
          provider?: string;
          result?: Record<string, unknown>;
      }
    | {
          type: 'task_created';
          id: string;
          title: string;
          status: string;
          schedule_type: string;
          task_url?: string;
          message?: string;
      }
    | {
          type: 'task_workflow' | 'report_delivery_workflow';
          id: string;
          status: string;
          current_step: string;
          confirmation_required: boolean;
          message: string;
          summary?: { merchants_count?: number; total_matched_orders?: number };
          merchants?: Array<{
              merchant: string;
              start_date?: string | null;
              end_date?: string | null;
              matched_count?: number;
              matched_orders?: Array<{
                  id: number;
                  order_no: string;
                  code: string;
                  status?: string;
                  order_date?: string | null;
              }>;
          }>;
          report_links?: Array<{ merchant: string; url: string }>;
          confirm_url?: string;
          task_url?: string;
      }
    | {
          type: 'list_product_records';
          table?: string;
          total: number;
          current_page: number;
          last_page: number;
          per_page: number;
          rows: OrderRow[];
      }
    | {
          type: 'get_product_record';
          table?: string;
          record: OrderRow;
      }
    | {
          type: 'model_workspace';
          action?: string;
          model?: string | null;
          table?: string | null;
          count?: number;
          column_count?: number;
          columns?: string[];
          available_tool_functions?: string[];
          created?: Array<Record<string, unknown>>;
          skipped?: Array<Record<string, unknown>>;
      }
    | {
          type: 'policy_blocked' | 'tool_scaffolded';
          message?: string;
          tool?: string;
          reason?: string;
          risk?: string;
          details?: string;
          upstream_status?: number;
      }
    | { type: 'error'; message: string };

type ToolCall = {
    tool: string;
    args: Record<string, unknown>;
    status?: 'running' | 'completed' | 'failed';
    resultType?: string;
};

type AgentActivityItem = {
    id: string;
    kind:
        | 'status'
        | 'plan_step'
        | 'tool_call'
        | 'tool_result'
        | 'critic'
        | 'note';
    title: string;
    detail?: string;
    tool?: string;
    state?: 'running' | 'completed' | 'failed';
};

type ChatMessage = {
    id: string;
    role: ChatRole;
    content: string;
    thinking?: string;
    toolCalls?: ToolCall[];
    toolResults?: ToolResult[];
    activity?: AgentActivityItem[];
};

type ToastType = 'info' | 'success' | 'error';

type Toast = {
    id: string;
    title: string;
    description?: string;
    type: ToastType;
};

type ContextUsage = {
    prompt_eval_count: number;
    eval_count: number;
    context_window: number;
    context_used_pct: number;
    context_remaining: number;
    iteration?: number;
};

type ChatPageProps = {
    initialConversationId?: string | null;
    initialMessages?: ChatMessage[];
};

type AutoTableData = {
    id: string;
    title: string;
    rows: OrderRow[];
    total?: number;
    currentPage?: number;
    lastPage?: number;
    perPage?: number;
};

type ReportTypeOption = 'financial' | 'merchant' | 'call_center';

// ── Constants ────────────────────────────────────────────────────────────────

const breadcrumbs: BreadcrumbItem[] = [{ title: 'AI Chat', href: '/chat' }];
const starterMessages: ChatMessage[] = [];

const TABLE_COLS = [
    { key: 'order_no', label: 'Order #' },
    { key: 'client_name', label: 'Client' },
    { key: 'product_name', label: 'Product' },
    { key: 'amount', label: 'Amount' },
    { key: 'quantity', label: 'Qty' },
    { key: 'status', label: 'Status' },
    { key: 'agent', label: 'Agent' },
    { key: 'city', label: 'City' },
    { key: 'delivery_date', label: 'Delivery' },
];

const ORDER_DETAIL_PRIORITY = [
    'order_no',
    'client_name',
    'product_name',
    'amount',
    'quantity',
    'status',
    'agent',
    'city',
    'delivery_date',
    'order_date',
    'country',
    'phone',
    'address',
    'client_city',
    'store_name',
    'comments',
    'instructions',
    'invoice_code',
    'sheet_name',
];

const STATUS_STYLES: Record<string, string> = {
    delivered: 'bg-emerald-100 text-emerald-700 border-emerald-200',
    pending: 'bg-amber-100  text-amber-700  border-amber-200',
    cancelled: 'bg-red-100    text-red-700    border-red-200',
    processing: 'bg-blue-100   text-blue-700   border-blue-200',
    shipped: 'bg-violet-100 text-violet-700  border-violet-200',
};

const TOOL_META: Record<
    string,
    { label: string; icon: ReactNode; color: string }
> = {
    list_orders: {
        label: 'Querying orders',
        icon: <Table2 className="h-3 w-3" />,
        color: 'text-blue-600 bg-blue-50 border-blue-200',
    },
    get_order: {
        label: 'Fetching order',
        icon: <Package className="h-3 w-3" />,
        color: 'text-violet-600 bg-violet-50 border-violet-200',
    },
    create_order: {
        label: 'Creating order',
        icon: <PackagePlus className="h-3 w-3" />,
        color: 'text-emerald-600 bg-emerald-50 border-emerald-200',
    },
    edit_order: {
        label: 'Updating order',
        icon: <PackageCheck className="h-3 w-3" />,
        color: 'text-orange-600 bg-orange-50 border-orange-200',
    },
    create_task: {
        label: 'Scheduling task',
        icon: <Gauge className="h-3 w-3" />,
        color: 'text-fuchsia-600 bg-fuchsia-50 border-fuchsia-200',
    },
};

const THINKING_PLACEHOLDER_TEXTS = new Set([
    'Thinking...',
    'Planning the best steps...',
    'Executing tools...',
    'Request received. Preparing response...',
]);

// ── Helpers ───────────────────────────────────────────────────────────────────

function getCsrfToken(): string {
    return (
        document
            .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content?.trim() ?? ''
    );
}

function getXsrfTokenFromCookie(): string {
    const tokenPart = document.cookie
        .split('; ')
        .find((part) => part.startsWith('XSRF-TOKEN='));

    if (!tokenPart) return '';

    const value = tokenPart.slice('XSRF-TOKEN='.length);

    try {
        return decodeURIComponent(value);
    } catch {
        return value;
    }
}

function formatValue(key: string, value: unknown): string {
    if (value == null || value === '') return '—';
    if (key.includes('date') && typeof value === 'string') {
        try {
            return new Date(value).toLocaleDateString('en-GB', {
                day: '2-digit',
                month: 'short',
                year: 'numeric',
            });
        } catch {
            return String(value);
        }
    }
    if (key === 'amount' && typeof value === 'number') {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD',
        }).format(value);
    }
    return String(value);
}

function splitTerminalThinking(
    answerText: string,
    thinkingText: string,
): { answer: string; thinking: string } {
    const startMarker = 'Thinking...';
    const endMarker = '...done thinking.';
    if (!answerText.startsWith(startMarker))
        return { answer: answerText, thinking: thinkingText };
    const afterStart = answerText.slice(startMarker.length);
    const endIndex = afterStart.indexOf(endMarker);
    if (endIndex === -1)
        return { answer: '', thinking: `${thinkingText}${afterStart}`.trim() };
    return {
        answer: afterStart.slice(endIndex + endMarker.length).trim(),
        thinking:
            `${thinkingText}\n${afterStart.slice(0, endIndex).trim()}`.trim(),
    };
}

function splitHeuristicThinking(
    answerText: string,
    thinkingText: string,
): { answer: string; thinking: string } {
    const trimmed = answerText.trimStart();
    const looksLikeReasoning =
        /^thinking\b/i.test(trimmed) ||
        /^thinking process\b/i.test(trimmed) ||
        /^reasoning\b/i.test(trimmed) ||
        /^analysis\b/i.test(trimmed);
    if (!looksLikeReasoning)
        return { answer: answerText, thinking: thinkingText };
    const match =
        /\b(Final\s+(Answer|Response|Output|Decision)|Answer:|Response:)\b/i.exec(
            answerText,
        );
    if (!match || match.index === undefined)
        return {
            answer: '',
            thinking: `${thinkingText}\n${answerText}`.trim(),
        };
    return {
        answer: answerText
            .slice(match.index + match[0].length)
            .trim()
            .replace(/^[:\-\s]+/, ''),
        thinking:
            `${thinkingText}\n${answerText.slice(0, match.index).trim()}`.trim(),
    };
}

function isThinkingPlaceholderContent(content: string): boolean {
    return THINKING_PLACEHOLDER_TEXTS.has(content.trim());
}

function extractQuestionText(content: string): string | null {
    const normalized = content.replace(/\s+/g, ' ').trim();
    if (!normalized || !normalized.includes('?')) {
        return null;
    }

    const lower = normalized.toLowerCase();
    const clarificationSignals = [
        'please clarify',
        'clarify',
        'i need',
        'need a',
        'need the',
        'missing',
        'required',
        'to proceed',
        'could you',
        'can you',
        'would you',
        'which',
        'what is',
        'what should',
        'confirm',
        'not sure',
        'unsure',
    ];
    const isClarification = clarificationSignals.some((signal) =>
        lower.includes(signal),
    );
    if (!isClarification) {
        return null;
    }

    const parts = normalized
        .split(/(?<=\?)/)
        .map((part) => part.trim())
        .filter((part) => part.length > 0);
    const lastQuestion = [...parts]
        .reverse()
        .find((part) => part.includes('?'));

    return lastQuestion ?? null;
}

function isTaskPayload(value: unknown): value is TaskPayload {
    if (typeof value !== 'object' || value === null) {
        return false;
    }

    const data = value as Record<string, unknown>;
    return (
        typeof data.title === 'string' &&
        typeof data.schedule_type === 'string' &&
        ['immediate', 'one_time', 'recurring', 'event_triggered'].includes(
            data.schedule_type,
        )
    );
}

function detectSpecificReportType(prompt: string): ReportTypeOption | null {
    const text = prompt.toLowerCase();

    if (/(call[\s-]?center|call center|cc report)/i.test(text)) {
        return 'call_center';
    }
    if (/\bmerchant\b/i.test(text)) {
        return 'merchant';
    }
    if (/(financial|finance|revenue|profit|sales)/i.test(text)) {
        return 'financial';
    }

    return null;
}

function shouldPromptForReportType(prompt: string): boolean {
    const text = prompt.trim().toLowerCase();
    if (text === '') return false;

    const hasReportIntent = /\breport(s|ing)?\b/.test(text);
    if (!hasReportIntent) return false;

    return detectSpecificReportType(text) === null;
}

function buildReportSelectionPrompt(
    originalPrompt: string,
    reportType: ReportTypeOption,
    enhancement = '',
): string {
    const instruction =
        reportType === 'financial'
            ? 'Selected report type: financial report. Use financial_report.'
            : reportType === 'merchant'
              ? 'Selected report type: merchant report. Use merchant_report.'
              : 'Selected report type: call center report. Use call_center_daily_report or call_center_monthly_report based on requested period.';

    const extra = enhancement.trim();
    if (extra === '') {
        return `${originalPrompt}\n\n${instruction}`;
    }

    return `${originalPrompt}\n\n${instruction}\nAdditional user preference: ${extra}`;
}

const DEFAULT_STATUS_OPTIONS = [
    'delivered',
    'scheduled',
    'pending',
    'cancelled',
    'processing',
    'shipped',
];

function normalizeOptionText(value: string): string {
    return value
        .trim()
        .replace(/^["'`]+|["'`]+$/g, '')
        .replace(/[.,;:!?]+$/, '')
        .trim();
}

function isViableOptionText(value: string): boolean {
    const text = normalizeOptionText(value);
    if (text === '') return false;
    if (text.length > 80) return false;
    if (/[?]/.test(text)) return false;
    if (/\b(and optionally|choose one option|popup closes)\b/i.test(text)) {
        return false;
    }

    const wordCount = text.split(/\s+/).filter(Boolean).length;
    if (wordCount > 10) return false;

    return true;
}

function uniqueOptions(values: string[]): string[] {
    const seen = new Set<string>();
    const options: string[] = [];

    for (const value of values) {
        const option = normalizeOptionText(value);
        if (!isViableOptionText(option)) continue;
        const key = option.toLowerCase();
        if (seen.has(key)) continue;
        seen.add(key);
        options.push(option);
    }

    return options;
}

function extractOptionsFromAssistantContent(content: string): string[] {
    const lines = content
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line !== '');
    const options: string[] = [];

    for (const line of lines) {
        const bulletMatch = line.match(/^[-*]\s+(.+)$/);
        if (bulletMatch?.[1]) {
            options.push(bulletMatch[1]);
            continue;
        }

        const numberedMatch = line.match(/^\d+\.\s+(.+)$/);
        if (numberedMatch?.[1]) {
            options.push(numberedMatch[1]);
            continue;
        }
    }

    if (options.length >= 2) {
        return uniqueOptions(options);
    }

    const inlineMatch = content.match(
        /\b(?:options?|choose from|select from)\s*[:\-]\s*([^\n]+)/i,
    );
    if (inlineMatch?.[1]) {
        const inline = inlineMatch[1]
            .split(',')
            .map((part) => part.trim());
        const parsed = uniqueOptions(inline);
        if (parsed.length >= 2) return parsed;
    }

    return [];
}

function optionsFromToolResults(results: ToolResult[] | undefined): string[] {
    if (!results || results.length === 0) return [];
    const keyCandidates = [
        'provider_options',
        'options',
        'choices',
        'status_options',
        'available_options',
    ];

    for (let i = results.length - 1; i >= 0; i -= 1) {
        const item = results[i];
        if (!isRecordObject(item)) continue;

        for (const key of keyCandidates) {
            const value = (item as Record<string, unknown>)[key];
            if (!Array.isArray(value)) continue;
            const opts = uniqueOptions(
                value.filter((v): v is string => typeof v === 'string'),
            );
            if (opts.length >= 2) return opts;
        }
    }

    return [];
}

function deriveAssistantChoice(
    message: ChatMessage | null,
): { question: string; options: string[] } | null {
    if (!message || message.role !== 'assistant') return null;

    const question =
        extractQuestionText(message.content) ??
        ((message.toolResults ?? []).find(
            (result) =>
                result.type === 'integration_requirements' &&
                Array.isArray(result.questions) &&
                result.questions.length > 0,
        ) as Extract<ToolResult, { type: 'integration_requirements' }> | undefined)
            ?.questions?.[0] ??
        null;

    if (!question) return null;

    let options = optionsFromToolResults(message.toolResults);
    if (options.length < 2) {
        options = extractOptionsFromAssistantContent(message.content);
    }
    if (options.length < 2 && /\bstatus\b/i.test(question)) {
        options = DEFAULT_STATUS_OPTIONS;
    }

    if (options.length < 2) return null;

    return { question, options };
}

function shortJson(value: unknown): string {
    try {
        const rendered = JSON.stringify(value);
        if (!rendered) return '';
        return rendered.length > 220
            ? `${rendered.slice(0, 220)}...`
            : rendered;
    } catch {
        return '';
    }
}

function isRecordObject(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function isObjectArray(value: unknown): value is OrderRow[] {
    return (
        Array.isArray(value) &&
        value.length > 0 &&
        value.every((item) => isRecordObject(item))
    );
}

function labelFromKey(key: string): string {
    return key
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (char) => char.toUpperCase())
        .trim();
}

function safeCellValue(key: string, value: unknown): string {
    if (/(password|secret|token|recovery|api_key|auth)/i.test(key)) {
        return '••••';
    }
    if (Array.isArray(value)) {
        return value.length > 0 ? JSON.stringify(value) : '[]';
    }
    if (isRecordObject(value)) {
        return JSON.stringify(value);
    }

    return formatValue(key, value);
}

function collectAutoTables(result: Record<string, unknown>): AutoTableData[] {
    const type = String(result.type ?? 'tool_result');
    const candidates: Array<[string, unknown]> = Object.entries(result).filter(
        ([field]) => !field.startsWith('_') && field !== 'type',
    );
    const tables: AutoTableData[] = [];

    for (const [field, value] of candidates) {
        if (!isObjectArray(value)) {
            continue;
        }

        tables.push({
            id: `${type}:${field}`,
            title: `${labelFromKey(type)} - ${labelFromKey(field)}`,
            rows: value,
            total:
                typeof result.total === 'number'
                    ? result.total
                    : value.length,
            currentPage:
                typeof result.current_page === 'number'
                    ? result.current_page
                    : undefined,
            lastPage:
                typeof result.last_page === 'number'
                    ? result.last_page
                    : undefined,
            perPage:
                typeof result.per_page === 'number'
                    ? result.per_page
                    : undefined,
        });
    }

    return tables;
}

// ── Rich text renderer ────────────────────────────────────────────────────────

function renderRichText(text: string): ReactNode {
    const renderPlainWithLinks = (
        value: string,
        keyPrefix: string,
    ): ReactNode[] => {
        const nodes: ReactNode[] = [];
        const linkPattern =
            /(https?:\/\/[^\s)]+|\/(?:tasks|reports|report-tasks|chat|dashboard)[^\s)]*)/g;
        const parts = value.split(linkPattern);

        parts.forEach((part, index) => {
            if (!part) return;
            if (
                /^https?:\/\//.test(part) ||
                /^\/(?:tasks|reports|report-tasks|chat|dashboard)/.test(part)
            ) {
                nodes.push(
                    <a
                        key={`${keyPrefix}-lnk-${index}`}
                        href={part}
                        className="underline underline-offset-2 hover:opacity-80"
                        target={part.startsWith('http') ? '_blank' : undefined}
                        rel={
                            part.startsWith('http')
                                ? 'noreferrer noopener'
                                : undefined
                        }
                    >
                        {part}
                    </a>,
                );
                return;
            }
            nodes.push(<span key={`${keyPrefix}-txt-${index}`}>{part}</span>);
        });

        return nodes;
    };

    const renderInline = (
        inlineText: string,
        keyPrefix: string,
    ): ReactNode[] => {
        const segments: ReactNode[] = [];
        const pattern = /(`[^`]+`|\*\*[^*]+\*\*|__[^_]+__|\*[^*]+\*|_[^_]+_)/g;
        const parts = inlineText.split(pattern);
        parts.forEach((part, index) => {
            if (
                ((part.startsWith('**') && part.endsWith('**')) ||
                    (part.startsWith('__') && part.endsWith('__'))) &&
                part.length > 4
            ) {
                segments.push(
                    <strong
                        key={`${keyPrefix}-b-${index}`}
                        className="font-semibold"
                    >
                        {part.slice(2, -2)}
                    </strong>,
                );
                return;
            }
            if (part.startsWith('`') && part.endsWith('`') && part.length > 2) {
                segments.push(
                    <code
                        key={`${keyPrefix}-c-${index}`}
                        className="rounded bg-slate-200 px-1.5 py-0.5 font-mono text-[0.85em] text-slate-900"
                    >
                        {part.slice(1, -1)}
                    </code>,
                );
                return;
            }
            if (
                ((part.startsWith('*') && part.endsWith('*')) ||
                    (part.startsWith('_') && part.endsWith('_'))) &&
                part.length > 2
            ) {
                segments.push(
                    <em key={`${keyPrefix}-i-${index}`} className="italic">
                        {part.slice(1, -1)}
                    </em>,
                );
                return;
            }
            if (part.length > 0) {
                segments.push(
                    <span key={`${keyPrefix}-t-${index}`}>
                        {renderPlainWithLinks(part, `${keyPrefix}-p-${index}`)}
                    </span>,
                );
            }
        });
        return segments;
    };

    const blocks = text.split(/```/);
    return blocks.map((block, blockIndex) => {
        if (blockIndex % 2 === 1) {
            const [firstLine, ...rest] = block.split('\n');
            const hasLanguage = !/\s/.test(firstLine.trim()) && rest.length > 0;
            const language = hasLanguage ? firstLine.trim() : '';
            const code = hasLanguage ? rest.join('\n') : block;
            return (
                <div
                    key={`code-${blockIndex}`}
                    className="my-2 overflow-hidden rounded-lg border border-slate-700 bg-[#1e1e1e]"
                >
                    {language && (
                        <div className="border-b border-slate-700 bg-[#252526] px-3 py-1 text-[11px] tracking-wide text-slate-300 uppercase">
                            {language}
                        </div>
                    )}
                    <pre className="overflow-x-auto px-3 py-2 text-sm leading-6 text-slate-100">
                        <code className="font-mono">{code}</code>
                    </pre>
                </div>
            );
        }
        const rawLines = block.split('\n');
        const lines: string[] = [];

        // Normalize common LLM formatting artifacts like:
        // "1." on one line and content on the next line.
        for (let i = 0; i < rawLines.length; i += 1) {
            const current = rawLines[i] ?? '';
            const next = rawLines[i + 1] ?? '';

            if (/^\s*\d+\.\s*$/.test(current) && next.trim() !== '') {
                lines.push(`${current.trim()} ${next.trim()}`);
                i += 1;
                continue;
            }

            if (/^\s*[-*+]\s*$/.test(current) && next.trim() !== '') {
                lines.push(`${current.trim()} ${next.trim()}`);
                i += 1;
                continue;
            }

            lines.push(current);
        }

        const rowsFromPipeLine = (line: string): string[] =>
            line
                .trim()
                .replace(/^\|/, '')
                .replace(/\|$/, '')
                .split('|')
                .map((cell) => cell.trim());

        const isTableSeparatorLine = (line: string): boolean => {
            const cells = rowsFromPipeLine(line);
            return (
                cells.length > 0 &&
                cells.every((cell) => /^:?-{3,}:?$/.test(cell))
            );
        };

        const renderedLines: ReactNode[] = [];
        for (let lineIndex = 0; lineIndex < lines.length; lineIndex += 1) {
            const line = lines[lineIndex] ?? '';

            if (/^\s*---+\s*$/.test(line)) {
                renderedLines.push(
                    <hr
                        key={`hr-${blockIndex}-${lineIndex}`}
                        className="my-2 border-slate-200"
                    />,
                );
                continue;
            }

            const isPotentialTable = line.includes('|');
            if (isPotentialTable) {
                const tableLines: string[] = [line];
                let cursor = lineIndex + 1;
                while (cursor < lines.length) {
                    const candidate = lines[cursor] ?? '';
                    if (candidate.trim() === '' || !candidate.includes('|'))
                        break;
                    tableLines.push(candidate);
                    cursor += 1;
                }

                const hasHeaderSeparator =
                    tableLines.length >= 2 &&
                    isTableSeparatorLine(tableLines[1]);
                if (hasHeaderSeparator) {
                    const header = rowsFromPipeLine(tableLines[0]);
                    const bodyRows = tableLines.slice(2).map(rowsFromPipeLine);

                    renderedLines.push(
                        <div
                            key={`table-${blockIndex}-${lineIndex}`}
                            className="my-2 overflow-x-auto rounded border border-slate-200"
                        >
                            <table className="w-full min-w-[420px] text-left text-xs">
                                <thead className="bg-slate-50">
                                    <tr className="border-b border-slate-200">
                                        {header.map((cell, i) => (
                                            <th
                                                key={`th-${i}`}
                                                className="px-2.5 py-2 font-semibold text-slate-700"
                                            >
                                                {renderInline(
                                                    cell,
                                                    `th-${blockIndex}-${lineIndex}-${i}`,
                                                )}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 bg-white">
                                    {bodyRows.map((row, rowIndex) => (
                                        <tr key={`tr-${rowIndex}`}>
                                            {row.map((cell, cellIndex) => (
                                                <td
                                                    key={`td-${rowIndex}-${cellIndex}`}
                                                    className="px-2.5 py-1.5 text-slate-700"
                                                >
                                                    {renderInline(
                                                        cell,
                                                        `td-${blockIndex}-${lineIndex}-${rowIndex}-${cellIndex}`,
                                                    )}
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>,
                    );

                    lineIndex = cursor - 1;
                    continue;
                }
            }

            const headingMatch = /^(#{1,6})\s+(.*)$/.exec(line);
            if (headingMatch) {
                const level = headingMatch[1].length;
                const sizeClass =
                    level === 1
                        ? 'text-xl font-bold'
                        : level === 2
                          ? 'text-lg font-semibold'
                          : 'text-base font-semibold';
                renderedLines.push(
                    <div
                        key={`h-${blockIndex}-${lineIndex}`}
                        className={`${sizeClass} whitespace-pre-wrap`}
                    >
                        {renderInline(
                            headingMatch[2],
                            `h-${blockIndex}-${lineIndex}`,
                        )}
                    </div>,
                );
                continue;
            }

            const unorderedMatch = /^\s*[-*+]\s+(.*)$/.exec(line);
            if (unorderedMatch) {
                renderedLines.push(
                    <div
                        key={`ul-${blockIndex}-${lineIndex}`}
                        className="flex items-start gap-2 whitespace-pre-wrap"
                    >
                        <span className="mt-1 text-slate-500">•</span>
                        <span>
                            {renderInline(
                                unorderedMatch[1],
                                `ul-${blockIndex}-${lineIndex}`,
                            )}
                        </span>
                    </div>,
                );
                continue;
            }

            const orderedMatch = /^\s*(\d+)\.\s+(.*)$/.exec(line);
            if (orderedMatch) {
                renderedLines.push(
                    <div
                        key={`ol-${blockIndex}-${lineIndex}`}
                        className="flex items-start gap-2 whitespace-pre-wrap"
                    >
                        <span className="mt-0.5 text-slate-500">
                            {orderedMatch[1]}.
                        </span>
                        <span>
                            {renderInline(
                                orderedMatch[2],
                                `ol-${blockIndex}-${lineIndex}`,
                            )}
                        </span>
                    </div>,
                );
                continue;
            }

            renderedLines.push(
                <div
                    key={`line-${blockIndex}-${lineIndex}`}
                    className="whitespace-pre-wrap"
                >
                    {line.length > 0 ? (
                        renderInline(line, `line-${blockIndex}-${lineIndex}`)
                    ) : (
                        <span>&nbsp;</span>
                    )}
                </div>,
            );
        }

        return (
            <div key={`text-${blockIndex}`} className="space-y-1">
                {renderedLines}
            </div>
        );
    });
}

// ── Tool call indicator ───────────────────────────────────────────────────────

function ToolCallBadge({ toolCall }: { toolCall: ToolCall }) {
    const meta = TOOL_META[toolCall.tool] ?? {
        label: toolCall.tool,
        icon: <Wrench className="h-3 w-3" />,
        color: 'text-slate-600 bg-slate-50 border-slate-200',
    };
    const isRunning = (toolCall.status ?? 'running') === 'running';
    const isFailed = toolCall.status === 'failed';
    return (
        <div
            className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-medium ${meta.color}`}
        >
            {meta.icon}
            <span>{meta.label}</span>
            {isRunning && (
                <Loader2 className="h-2.5 w-2.5 animate-spin opacity-60" />
            )}
            {!isRunning && !isFailed && (
                <CheckCircle2 className="h-2.5 w-2.5 opacity-70" />
            )}
            {isFailed && <AlertCircle className="h-2.5 w-2.5 opacity-70" />}
        </div>
    );
}

function AgentActivityTimeline({ items }: { items: AgentActivityItem[] }) {
    if (items.length === 0) return null;

    const [visibleCount, setVisibleCount] = useState(
        Math.min(1, items.length),
    );

    useEffect(() => {
        if (items.length < visibleCount) {
            setVisibleCount(items.length);
            return;
        }

        if (items.length <= visibleCount) return;

        const timer = window.setTimeout(() => {
            setVisibleCount((prev) => Math.min(items.length, prev + 1));
        }, 130);

        return () => window.clearTimeout(timer);
    }, [items.length, visibleCount]);

    const visibleItems = items.slice(0, visibleCount);

    const iconFor = (item: AgentActivityItem): ReactNode => {
        if (item.state === 'running') {
            return <Loader2 className="h-3.5 w-3.5 animate-spin" />;
        }
        if (item.state === 'failed') {
            return <AlertCircle className="h-3.5 w-3.5" />;
        }

        if (item.kind === 'tool_call') {
            return <Wrench className="h-3.5 w-3.5" />;
        }
        if (item.kind === 'status' || item.kind === 'note') {
            return <Sparkles className="h-3.5 w-3.5" />;
        }

        return <CheckCircle2 className="h-3.5 w-3.5" />;
    };

    return (
        <div className="overflow-x-auto pb-1">
            <div className="inline-flex min-w-max items-center gap-2">
                {visibleItems.map((item) => {
                    const isRunning = item.state === 'running';
                    const isFailed = item.state === 'failed';
                    const chipClass = isFailed
                        ? 'border-red-200 bg-red-50 text-red-700'
                        : isRunning
                          ? 'border-blue-200 bg-blue-50 text-blue-700'
                          : 'border-emerald-200 bg-emerald-50 text-emerald-700';

                    return (
                        <div
                            key={item.id}
                            title={
                                item.detail
                                    ? `${item.title}\n${item.detail}`
                                    : item.title
                            }
                            className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-medium ${chipClass}`}
                        >
                            {iconFor(item)}
                            <span className="max-w-[180px] truncate">
                                {item.title}
                            </span>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

// ── Status badge ──────────────────────────────────────────────────────────────

function StatusBadge({ status }: { status: string }) {
    const normalized = (status ?? '').toLowerCase();
    const style =
        STATUS_STYLES[normalized] ??
        'bg-slate-100 text-slate-600 border-slate-200';
    return (
        <span
            className={`inline-block rounded-full border px-2 py-0.5 text-[10px] font-semibold capitalize ${style}`}
        >
            {status || '—'}
        </span>
    );
}

// ── Orders table with pagination ──────────────────────────────────────────────

function OrdersTable({
    result,
    onPageChange,
    onViewOrder,
}: {
    result: Extract<ToolResult, { type: 'orders_table' }>;
    onPageChange?: (page: number) => void;
    onViewOrder?: (order: OrderRow) => void;
}) {
    if (!result.orders.length) {
        return (
            <div className="rounded-lg border border-slate-200 bg-slate-50 px-4 py-6 text-center text-sm text-slate-500">
                No orders found.
            </div>
        );
    }

    return (
        <div className="space-y-2">
            <div className="flex items-center justify-between px-0.5">
                <p className="text-xs font-medium text-slate-500">
                    {result.total.toLocaleString()} order
                    {result.total !== 1 ? 's' : ''} found
                </p>
                {result.last_page > 1 && (
                    <p className="text-xs text-slate-400">
                        Page {result.current_page} of {result.last_page}
                    </p>
                )}
            </div>

            <div className="overflow-x-auto rounded-lg border border-slate-200 shadow-sm">
                <table className="w-full min-w-[700px] text-left text-xs">
                    <thead>
                        <tr className="border-b border-slate-200 bg-slate-50">
                            {TABLE_COLS.map((col) => (
                                <th
                                    key={col.key}
                                    className="px-3 py-2.5 font-semibold whitespace-nowrap text-slate-600"
                                >
                                    {col.label}
                                </th>
                            ))}
                            <th className="px-3 py-2.5 font-semibold whitespace-nowrap text-slate-600">
                                View
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 bg-white">
                        {result.orders.map((order, i) => (
                            <tr
                                key={String(
                                    order['id'] ?? order['order_no'] ?? i,
                                )}
                                className="transition-colors hover:bg-slate-50/80"
                            >
                                {TABLE_COLS.map((col) => (
                                    <td
                                        key={col.key}
                                        className="px-3 py-2 whitespace-nowrap text-slate-700"
                                    >
                                        {col.key === 'status' ? (
                                            <StatusBadge
                                                status={String(
                                                    order[col.key] ?? '',
                                                )}
                                            />
                                        ) : col.key === 'amount' ? (
                                            <span className="font-medium tabular-nums">
                                                {formatValue(
                                                    col.key,
                                                    order[col.key],
                                                )}
                                            </span>
                                        ) : (
                                            formatValue(col.key, order[col.key])
                                        )}
                                    </td>
                                ))}
                                <td className="px-3 py-2 whitespace-nowrap text-slate-700">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        className="h-7 px-2 text-xs"
                                        onClick={() => onViewOrder?.(order)}
                                    >
                                        View
                                    </Button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {result.last_page > 1 && (
                <div className="flex items-center justify-between pt-1">
                    <button
                        disabled={result.current_page <= 1}
                        onClick={() => onPageChange?.(result.current_page - 1)}
                        className="inline-flex items-center gap-1 rounded-md border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-600 shadow-sm transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        <ChevronLeft className="h-3 w-3" /> Prev
                    </button>

                    <div className="flex items-center gap-1">
                        {Array.from(
                            { length: Math.min(result.last_page, 7) },
                            (_, i) => {
                                const page = i + 1;
                                const isCurrent = page === result.current_page;
                                return (
                                    <button
                                        key={page}
                                        onClick={() => onPageChange?.(page)}
                                        className={`h-6 min-w-[24px] rounded px-1.5 text-xs font-medium transition ${
                                            isCurrent
                                                ? 'bg-slate-800 text-white'
                                                : 'text-slate-500 hover:bg-slate-100'
                                        }`}
                                    >
                                        {page}
                                    </button>
                                );
                            },
                        )}
                        {result.last_page > 7 && (
                            <span className="px-1 text-slate-400">…</span>
                        )}
                    </div>

                    <button
                        disabled={result.current_page >= result.last_page}
                        onClick={() => onPageChange?.(result.current_page + 1)}
                        className="inline-flex items-center gap-1 rounded-md border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-600 shadow-sm transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        Next <ChevronRight className="h-3 w-3" />
                    </button>
                </div>
            )}
        </div>
    );
}

function SimpleOrdersTable({
    orders,
    onViewOrder,
}: {
    orders: OrderRow[];
    onViewOrder?: (order: OrderRow) => void;
}) {
    if (!orders.length) {
        return null;
    }

    return (
        <div className="overflow-x-auto rounded-lg border border-slate-200 shadow-sm">
            <table className="w-full min-w-[700px] text-left text-xs">
                <thead>
                    <tr className="border-b border-slate-200 bg-slate-50">
                        {TABLE_COLS.map((col) => (
                            <th
                                key={col.key}
                                className="px-3 py-2.5 font-semibold whitespace-nowrap text-slate-600"
                            >
                                {col.label}
                            </th>
                        ))}
                        <th className="px-3 py-2.5 font-semibold whitespace-nowrap text-slate-600">
                            View
                        </th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 bg-white">
                    {orders.map((order, i) => (
                        <tr
                            key={String(order['id'] ?? order['order_no'] ?? i)}
                            className="transition-colors hover:bg-slate-50/80"
                        >
                            {TABLE_COLS.map((col) => (
                                <td
                                    key={col.key}
                                    className="px-3 py-2 whitespace-nowrap text-slate-700"
                                >
                                    {col.key === 'status' ? (
                                        <StatusBadge
                                            status={String(
                                                order[col.key] ?? '',
                                            )}
                                        />
                                    ) : col.key === 'amount' ? (
                                        <span className="font-medium tabular-nums">
                                            {formatValue(
                                                col.key,
                                                order[col.key],
                                            )}
                                        </span>
                                    ) : (
                                        formatValue(col.key, order[col.key])
                                    )}
                                </td>
                            ))}
                            <td className="px-3 py-2 whitespace-nowrap text-slate-700">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="h-7 px-2 text-xs"
                                    onClick={() => onViewOrder?.(order)}
                                >
                                    View
                                </Button>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function ProductStockTable({
    result,
    onViewProduct,
}: {
    result: Extract<ToolResult, { type: 'list_product_records' }>;
    onViewProduct?: (record: OrderRow) => void;
}) {
    if (!result.rows.length) {
        return (
            <div className="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-600">
                No products found.
            </div>
        );
    }

    const cols = [
        { key: 'name', label: 'Product' },
        { key: 'code', label: 'Code' },
        { key: 'quantity', label: 'Stock' },
        { key: 'store_name', label: 'Store' },
        { key: 'buying_price', label: 'Buying' },
        { key: 'selling_price', label: 'Selling' },
        { key: 'quantity_alert', label: 'Alert At' },
    ];

    return (
        <div className="space-y-2">
            <div className="flex items-center justify-between px-0.5">
                <p className="text-xs font-medium text-slate-500">
                    {result.total.toLocaleString()} products
                </p>
                <p className="text-xs text-slate-400">
                    Page {result.current_page} of {result.last_page}
                </p>
            </div>
            <div className="overflow-x-auto rounded-lg border border-slate-200 shadow-sm">
                <table className="w-full min-w-[760px] text-left text-xs">
                    <thead>
                        <tr className="border-b border-slate-200 bg-slate-50">
                            {cols.map((col) => (
                                <th
                                    key={col.key}
                                    className="px-3 py-2.5 font-semibold text-slate-600"
                                >
                                    {col.label}
                                </th>
                            ))}
                            <th className="px-3 py-2.5 font-semibold text-slate-600">
                                View
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 bg-white">
                        {result.rows.map((row, idx) => (
                            <tr key={String(row['id'] ?? row['code'] ?? idx)}>
                                {cols.map((col) => (
                                    <td
                                        key={col.key}
                                        className="px-3 py-2 whitespace-nowrap text-slate-700"
                                    >
                                        {col.key === 'quantity' ? (
                                            <span className="font-semibold tabular-nums">
                                                {String(row[col.key] ?? '0')}
                                            </span>
                                        ) : (
                                            formatValue(col.key, row[col.key])
                                        )}
                                    </td>
                                ))}
                                <td className="px-3 py-2 whitespace-nowrap">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        className="h-7 px-2 text-xs"
                                        onClick={() => onViewProduct?.(row)}
                                    >
                                        View
                                    </Button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

function GenericAutoTable({ table }: { table: AutoTableData }) {
    if (!table.rows.length) {
        return null;
    }

    const preferred = [
        'id',
        'uuid',
        'order_no',
        'code',
        'name',
        'title',
        'status',
        'created_at',
        'updated_at',
    ];
    const seen = new Set<string>();
    const columns: string[] = [];

    for (const key of preferred) {
        if (table.rows.some((row) => Object.prototype.hasOwnProperty.call(row, key))) {
            columns.push(key);
            seen.add(key);
        }
    }

    for (const row of table.rows.slice(0, 80)) {
        for (const key of Object.keys(row)) {
            if (seen.has(key)) continue;
            columns.push(key);
            seen.add(key);
        }
    }

    const visibleColumns = columns.slice(0, 12);

    return (
        <div className="space-y-2">
            <div className="flex items-center justify-between px-0.5">
                <p className="text-xs font-medium text-slate-600">{table.title}</p>
                <p className="text-xs text-slate-400">
                    {(table.total ?? table.rows.length).toLocaleString()} row
                    {(table.total ?? table.rows.length) === 1 ? '' : 's'}
                    {table.currentPage && table.lastPage
                        ? ` · Page ${table.currentPage} of ${table.lastPage}`
                        : ''}
                    {table.perPage ? ` · Per page ${table.perPage}` : ''}
                </p>
            </div>
            <div className="overflow-x-auto rounded-lg border border-slate-200 shadow-sm">
                <table className="w-full min-w-[760px] text-left text-xs">
                    <thead>
                        <tr className="border-b border-slate-200 bg-slate-50">
                            {visibleColumns.map((column) => (
                                <th
                                    key={column}
                                    className="px-3 py-2.5 font-semibold whitespace-nowrap text-slate-600"
                                >
                                    {labelFromKey(column)}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 bg-white">
                        {table.rows.map((row, idx) => (
                            <tr key={String(row.id ?? row.uuid ?? idx)}>
                                {visibleColumns.map((column) => (
                                    <td
                                        key={column}
                                        className="px-3 py-2 align-top whitespace-nowrap text-slate-700"
                                    >
                                        {safeCellValue(column, row[column])}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

// ── Tool results renderer ─────────────────────────────────────────────────────

function ToolResultsView({
    results,
    onViewOrder,
    onConfirmTask,
    confirmingTaskId,
}: {
    results: ToolResult[];
    onViewOrder: (order: OrderRow) => void;
    onConfirmTask: (taskId: string) => Promise<void>;
    confirmingTaskId: string | null;
}) {
    const rowsByKey = new Map<string, OrderRow>();
    const infos: string[] = [];
    const errors: string[] = [];
    const reports: Extract<ToolResult, { type: 'financial_report' }>[] = [];
    const orderTables: Extract<ToolResult, { type: 'orders_table' }>[] = [];
    const productTables: Extract<ToolResult, { type: 'list_product_records' }>[] =
        [];
    const autoTables: AutoTableData[] = [];
    const integrations: Array<
        Extract<
            ToolResult,
            { type: 'integration_requirements' | 'integration_setup' }
        >
    > = [];
    const messagingResults: Array<
        Extract<ToolResult, { type: 'whatsapp_message_sent' }>
    > = [];
    const createdTasks: Array<Extract<ToolResult, { type: 'task_created' }>> =
        [];
    const workspaceResults: Array<
        Extract<ToolResult, { type: 'model_workspace' }>
    > = [];
    const operationalResults: Array<
        Extract<
            ToolResult,
            { type: 'policy_blocked' | 'tool_scaffolded' }
        >
    > = [];
    const taskResults: Array<
        Extract<
            ToolResult,
            { type: 'task_workflow' | 'report_delivery_workflow' }
        >
    > = [];

    for (const result of results) {
        if (result.type === 'error') {
            errors.push(result.message);
            continue;
        }

        if (result.type === 'financial_report') {
            reports.push(result);
            for (const row of result.orders ?? []) {
                const key = String(
                    row['id'] ?? row['order_no'] ?? JSON.stringify(row),
                );
                rowsByKey.set(key, row);
            }
            continue;
        }
        if (
            result.type === 'integration_requirements' ||
            result.type === 'integration_setup'
        ) {
            integrations.push(result);
            continue;
        }
        if (result.type === 'whatsapp_message_sent') {
            messagingResults.push(result);
            continue;
        }
        if (result.type === 'task_created') {
            createdTasks.push(result);
            continue;
        }
        if (result.type === 'model_workspace') {
            workspaceResults.push(result);
            continue;
        }
        if (result.type === 'policy_blocked' || result.type === 'tool_scaffolded') {
            operationalResults.push(result);
            continue;
        }
        if (
            result.type === 'task_workflow' ||
            result.type === 'report_delivery_workflow'
        ) {
            taskResults.push(result);
            continue;
        }

        if (result.type === 'orders_table') {
            orderTables.push(result);
            continue;
        }
        if (result.type === 'list_product_records') {
            productTables.push(result);
            continue;
        }

        if (result.type === 'order_detail') {
            const row = result.order;
            const key = String(
                row['id'] ?? row['order_no'] ?? JSON.stringify(row),
            );
            rowsByKey.set(key, row);
            continue;
        }
        if (result.type === 'get_product_record') {
            const row = result.record;
            const key = String(
                row['id'] ?? row['code'] ?? JSON.stringify(row),
            );
            rowsByKey.set(key, row);
            continue;
        }

        if (
            result.type === 'order_created' ||
            result.type === 'order_updated'
        ) {
            infos.push(result.message);
            const row = result.order;
            const key = String(
                row['id'] ?? row['order_no'] ?? JSON.stringify(row),
            );
            rowsByKey.set(key, row);
            continue;
        }

        if (isRecordObject(result)) {
            autoTables.push(...collectAutoTables(result));
        }
    }

    const rows = Array.from(rowsByKey.values());

    return (
        <div className="space-y-2">
            {reports.map((report, i) => (
                <FinancialReportView key={`report-${i}`} report={report} />
            ))}
            {integrations.map((item, i) => (
                <IntegrationResultView key={`integration-${i}`} result={item} />
            ))}
            {messagingResults.map((item, i) => (
                <div
                    key={`wa-${i}`}
                    className="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-800"
                >
                    <p className="font-semibold">WhatsApp message sent</p>
                    <p>To: {item.to}</p>
                    {item.provider && <p>Provider: {item.provider}</p>}
                </div>
            ))}
            {taskResults.map((task, i) => (
                <TaskWorkflowView
                    key={`task-${task.id}-${i}`}
                    task={task}
                    onConfirmTask={onConfirmTask}
                    isConfirming={confirmingTaskId === task.id}
                />
            ))}
            {createdTasks.map((task, i) => (
                <div
                    key={`created-task-${task.id}-${i}`}
                    className="rounded-lg border border-fuchsia-200 bg-fuchsia-50 p-3 text-xs text-fuchsia-900"
                >
                    <p className="font-semibold">
                        {task.message ?? `Task "${task.title}" created.`}
                    </p>
                    {task.task_url && (
                        <a
                            href={task.task_url}
                            className="mt-1 inline-flex items-center underline"
                        >
                            View task
                        </a>
                    )}
                </div>
            ))}
            {workspaceResults.map((result, i) => (
                <div
                    key={`workspace-${i}`}
                    className="rounded-lg border border-cyan-200 bg-cyan-50 p-3 text-xs text-cyan-900"
                >
                    <p className="font-semibold">
                        Workspace: {result.action ?? 'model_workspace'}
                    </p>
                    {result.model && <p>Model: {result.model}</p>}
                    {result.table && <p>Table: {result.table}</p>}
                    {typeof result.count === 'number' && (
                        <p>Models found: {result.count}</p>
                    )}
                    {typeof result.column_count === 'number' && (
                        <p>Columns: {result.column_count}</p>
                    )}
                    {(result.available_tool_functions?.length ?? 0) > 0 && (
                        <p>
                            Tools:{' '}
                            {result.available_tool_functions!.join(', ')}
                        </p>
                    )}
                    {(result.columns?.length ?? 0) > 0 && (
                        <p>
                            Sample columns:{' '}
                            {result.columns!.slice(0, 8).join(', ')}
                        </p>
                    )}
                    {(result.created?.length ?? 0) > 0 && (
                        <p>Created tools: {result.created!.length}</p>
                    )}
                    {(result.skipped?.length ?? 0) > 0 && (
                        <p>Skipped tools: {result.skipped!.length}</p>
                    )}
                </div>
            ))}
            {operationalResults.map((result, i) => (
                <div
                    key={`ops-${i}`}
                    className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900"
                >
                    <p className="font-semibold">
                        {result.type.replace(/_/g, ' ')}
                    </p>
                    {result.message && <p>{result.message}</p>}
                    {'tool' in result && result.tool && <p>Tool: {result.tool}</p>}
                    {'risk' in result && result.risk && <p>Risk: {result.risk}</p>}
                    {'details' in result && result.details && (
                        <p>Details: {result.details}</p>
                    )}
                </div>
            ))}
            {orderTables.map((table, i) => (
                <OrdersTable
                    key={`orders-table-${i}`}
                    result={table}
                    onViewOrder={onViewOrder}
                />
            ))}
            {productTables.map((table, i) => (
                <ProductStockTable
                    key={`product-table-${i}`}
                    result={table}
                    onViewProduct={onViewOrder}
                />
            ))}
            {autoTables.map((table, i) => (
                <GenericAutoTable key={`${table.id}-${i}`} table={table} />
            ))}
            {infos.map((message, i) => (
                <div
                    key={`${message}-${i}`}
                    className="text-xs font-semibold text-slate-700"
                >
                    {message}
                </div>
            ))}
            {rows.length > 0 && (
                <SimpleOrdersTable orders={rows} onViewOrder={onViewOrder} />
            )}
            {errors.map((message, i) => (
                <div
                    key={`${message}-${i}`}
                    className="rounded-lg border border-red-200 bg-red-50 px-3 py-2.5 text-xs text-red-700"
                >
                    <span className="font-semibold">Error: </span>
                    {message}
                </div>
            ))}
        </div>
    );
}

function TaskWorkflowView({
    task,
    onConfirmTask,
    isConfirming,
}: {
    task: Extract<
        ToolResult,
        { type: 'task_workflow' | 'report_delivery_workflow' }
    >;
    onConfirmTask: (taskId: string) => Promise<void>;
    isConfirming: boolean;
}) {
    return (
        <div className="space-y-2 rounded-lg border border-indigo-200 bg-indigo-50 p-3 text-xs text-indigo-900">
            <div className="flex flex-wrap items-center gap-2">
                <Badge variant="secondary">Report Task</Badge>
                <Badge variant="outline">Status: {task.status}</Badge>
                <Badge variant="outline">Step: {task.current_step}</Badge>
            </div>
            <p className="font-medium">{task.message}</p>
            {(task.summary?.merchants_count ||
                task.summary?.total_matched_orders) && (
                <p>
                    Merchants: {task.summary?.merchants_count ?? 0} | Matched
                    Orders: {task.summary?.total_matched_orders ?? 0}
                </p>
            )}

            {(task.merchants?.length ?? 0) > 0 && (
                <div className="overflow-x-auto rounded border border-indigo-200 bg-white">
                    <table className="w-full min-w-[420px] text-left text-xs">
                        <thead>
                            <tr className="border-b border-indigo-100 bg-indigo-50">
                                <th className="px-2 py-1.5">Merchant</th>
                                <th className="px-2 py-1.5">Date Range</th>
                                <th className="px-2 py-1.5">Matched</th>
                            </tr>
                        </thead>
                        <tbody>
                            {task.merchants!.map((m, idx) => (
                                <tr
                                    key={`${m.merchant}-${idx}`}
                                    className="border-b border-indigo-50"
                                >
                                    <td className="px-2 py-1.5">
                                        {m.merchant}
                                    </td>
                                    <td className="px-2 py-1.5">
                                        {m.start_date ?? 'N/A'} to{' '}
                                        {m.end_date ?? 'N/A'}
                                    </td>
                                    <td className="px-2 py-1.5">
                                        {m.matched_count ?? 0}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {(() => {
                const matchedOrders = (task.merchants ?? []).flatMap(
                    (merchant) =>
                        (merchant.matched_orders ?? []).map((order) => ({
                            merchant: merchant.merchant,
                            ...order,
                        })),
                );

                if (matchedOrders.length === 0) return null;

                return (
                    <div className="overflow-x-auto rounded border border-indigo-200 bg-white">
                        <table className="w-full min-w-[560px] text-left text-xs">
                            <thead>
                                <tr className="border-b border-indigo-100 bg-indigo-50">
                                    <th className="px-2 py-1.5">Merchant</th>
                                    <th className="px-2 py-1.5">Order #</th>
                                    <th className="px-2 py-1.5">Code</th>
                                    <th className="px-2 py-1.5">Status</th>
                                    <th className="px-2 py-1.5">Order Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                {matchedOrders.map((order, idx) => (
                                    <tr
                                        key={`${order.id}-${idx}`}
                                        className="border-b border-indigo-50"
                                    >
                                        <td className="px-2 py-1.5">
                                            {order.merchant}
                                        </td>
                                        <td className="px-2 py-1.5">
                                            {order.order_no || '-'}
                                        </td>
                                        <td className="px-2 py-1.5">
                                            {order.code || '-'}
                                        </td>
                                        <td className="px-2 py-1.5">
                                            {order.status || '-'}
                                        </td>
                                        <td className="px-2 py-1.5">
                                            {order.order_date
                                                ? formatValue(
                                                      'order_date',
                                                      order.order_date,
                                                  )
                                                : '-'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                );
            })()}

            {(task.report_links?.length ?? 0) > 0 && (
                <div className="space-y-1">
                    {task.report_links!.map((link, idx) => (
                        <a
                            key={`${link.merchant}-${idx}`}
                            href={link.url}
                            className="inline-flex items-center rounded border border-indigo-200 bg-white px-2 py-1 text-xs hover:bg-indigo-100"
                        >
                            <Download className="mr-1 h-3 w-3" />
                            Download {link.merchant} report
                        </a>
                    ))}
                </div>
            )}

            {task.confirmation_required && (
                <Button
                    type="button"
                    size="sm"
                    disabled={isConfirming}
                    onClick={() => void onConfirmTask(task.id)}
                >
                    {isConfirming ? 'Proceeding...' : 'Proceed'}
                </Button>
            )}
        </div>
    );
}

function IntegrationResultView({
    result,
}: {
    result: Extract<
        ToolResult,
        { type: 'integration_requirements' | 'integration_setup' }
    >;
}) {
    if (result.type === 'integration_requirements') {
        return (
            <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
                <p className="font-semibold">{result.message}</p>
                {(result.provider_options?.length ?? 0) > 0 && (
                    <p className="mt-1">
                        Providers: {result.provider_options!.join(', ')}
                    </p>
                )}
                {(result.required_inputs?.length ?? 0) > 0 && (
                    <p className="mt-1">
                        Required inputs: {result.required_inputs!.join(', ')}
                    </p>
                )}
                {(result.questions?.length ?? 0) > 0 && (
                    <div className="mt-1 space-y-0.5">
                        {result.questions!.map((q, i) => (
                            <p key={`${q}-${i}`}>- {q}</p>
                        ))}
                    </div>
                )}
            </div>
        );
    }

    return (
        <div className="rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-900">
            <p className="font-semibold">{result.message}</p>
            <p className="mt-1">Provider: {result.provider}</p>
            {(result.missing_env_keys?.length ?? 0) > 0 && (
                <p className="mt-1">
                    Missing env keys: {result.missing_env_keys!.join(', ')}
                </p>
            )}
            {result.next_step && <p className="mt-1">{result.next_step}</p>}
        </div>
    );
}

function FinancialReportView({
    report,
}: {
    report: Extract<ToolResult, { type: 'financial_report' }>;
}) {
    const fmtMoney = (value: number) =>
        new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'KES',
            maximumFractionDigits: 2,
        }).format(value);

    return (
        <div className="space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
            <div className="flex flex-wrap items-center gap-2">
                <Badge variant="secondary">Financial Report</Badge>
                <Badge variant="outline">Status: {report.status}</Badge>
                {report.merchant && (
                    <Badge variant="outline">Merchant: {report.merchant}</Badge>
                )}
                {report.excel_download_url && (
                    <Button
                        asChild
                        size="sm"
                        variant="outline"
                        className="ml-auto h-7 px-2 text-[11px]"
                    >
                        <a href={report.excel_download_url}>
                            <Download className="mr-1 h-3.5 w-3.5" />
                            Download Excel
                        </a>
                    </Button>
                )}
            </div>

            <div className="grid grid-cols-1 gap-2 text-xs sm:grid-cols-3">
                <div className="rounded border border-slate-200 bg-white p-2">
                    <p className="text-slate-500">Total Orders</p>
                    <p className="font-semibold text-slate-900">
                        {report.total_orders.toLocaleString()}
                    </p>
                </div>
                <div className="rounded border border-slate-200 bg-white p-2">
                    <p className="text-slate-500">Total Revenue</p>
                    <p className="font-semibold text-slate-900">
                        {fmtMoney(report.total_revenue)}
                    </p>
                </div>
                <div className="rounded border border-slate-200 bg-white p-2">
                    <p className="text-slate-500">Average Order Value</p>
                    <p className="font-semibold text-slate-900">
                        {fmtMoney(report.average_order_value)}
                    </p>
                </div>
            </div>

            {(report.date_range?.earliest_order_date ||
                report.date_range?.latest_order_date) && (
                <div className="text-xs text-slate-700">
                    Date Range:{' '}
                    {formatValue(
                        'order_date',
                        report.date_range?.earliest_order_date,
                    )}{' '}
                    to{' '}
                    {formatValue(
                        'order_date',
                        report.date_range?.latest_order_date,
                    )}
                </div>
            )}

            {(report.product_breakdown?.length ?? 0) > 0 && (
                <div className="overflow-x-auto rounded border border-slate-200 bg-white">
                    <table className="w-full min-w-[520px] text-left text-xs">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50">
                                <th className="px-3 py-2 font-semibold text-slate-600">
                                    Product
                                </th>
                                <th className="px-3 py-2 font-semibold text-slate-600">
                                    Orders
                                </th>
                                <th className="px-3 py-2 font-semibold text-slate-600">
                                    Revenue
                                </th>
                                <th className="px-3 py-2 font-semibold text-slate-600">
                                    Avg Price
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {report.product_breakdown!.map((row, idx) => (
                                <tr key={`${row.product_name}-${idx}`}>
                                    <td className="px-3 py-2 text-slate-800">
                                        {row.product_name}
                                    </td>
                                    <td className="px-3 py-2 text-slate-700">
                                        {row.order_count}
                                    </td>
                                    <td className="px-3 py-2 text-slate-700">
                                        {fmtMoney(row.total_revenue)}
                                    </td>
                                    <td className="px-3 py-2 text-slate-700">
                                        {fmtMoney(row.average_price)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}

function OrderDetailsDialog({
    order,
    open,
    onOpenChange,
}: {
    order: OrderRow | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    if (!order) {
        return null;
    }

    const orderedKeys = [
        ...ORDER_DETAIL_PRIORITY.filter((key) => key in order),
        ...Object.keys(order).filter(
            (key) => !ORDER_DETAIL_PRIORITY.includes(key),
        ),
    ];

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        Order #{String(order['order_no'] ?? 'Details')}
                    </DialogTitle>
                </DialogHeader>
                <div className="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    {orderedKeys.map((key) => (
                        <div key={key}>
                            <p className="text-xs font-medium tracking-wide text-slate-500 uppercase">
                                {key.replace(/_/g, ' ')}
                            </p>
                            <p className="mt-0.5 text-slate-900">
                                {key === 'status' ? (
                                    <StatusBadge
                                        status={String(order[key] ?? '')}
                                    />
                                ) : (
                                    formatValue(key, order[key])
                                )}
                            </p>
                        </div>
                    ))}
                </div>
            </DialogContent>
        </Dialog>
    );
}

// ── Main Chat component ───────────────────────────────────────────────────────

export default function Chat({
    initialConversationId = null,
    initialMessages = starterMessages,
}: ChatPageProps) {
    const [messages, setMessages] = useState<ChatMessage[]>(initialMessages);
    const [input, setInput] = useState('');
    const [isTyping, setIsTyping] = useState(false);
    const [conversationId, setConversationId] = useState<string | null>(
        initialConversationId,
    );
    const [toasts, setToasts] = useState<Toast[]>([]);
    const [selectedOrder, setSelectedOrder] = useState<OrderRow | null>(null);
    const [confirmingTaskId, setConfirmingTaskId] = useState<string | null>(
        null,
    );
    const [contextUsage, setContextUsage] = useState<ContextUsage | null>(null);
    const [reportPickerOpen, setReportPickerOpen] = useState(false);
    const [pendingReportPrompt, setPendingReportPrompt] = useState<
        string | null
    >(null);
    const [reportCustomInput, setReportCustomInput] = useState('');
    const [assistantOptionPicker, setAssistantOptionPicker] = useState<{
        question: string;
        options: string[];
        sourceMessageId: string;
    } | null>(null);
    const [assistantOptionCustomInput, setAssistantOptionCustomInput] =
        useState('');
    const [isStopping, setIsStopping] = useState(false);
    const [abortController, setAbortController] =
        useState<AbortController | null>(null);

    const canSend = useMemo(
        () => input.trim().length > 0 && !isTyping,
        [input, isTyping],
    );

    const waitingForFirstResponseToken = useMemo(() => {
        if (!isTyping) return false;
        const latestAssistant = [...messages]
            .reverse()
            .find((m) => m.role === 'assistant');
        if (!latestAssistant) return true;
        return (
            latestAssistant.content.trim().length === 0 &&
            !latestAssistant.toolResults?.length
        );
    }, [messages, isTyping]);

    const waitingForPostToolNarration = useMemo(() => {
        if (!isTyping) return false;
        const latestAssistant = [...messages]
            .reverse()
            .find((m) => m.role === 'assistant');
        if (!latestAssistant) return false;
        const hasToolResults = (latestAssistant.toolResults ?? []).length > 0;
        const hasToolCalls = (latestAssistant.toolCalls ?? []).length > 0;
        const hasText = latestAssistant.content.trim().length > 0;
        return hasToolResults && !hasText && !hasToolCalls;
    }, [messages, isTyping]);

    const activeAssistantId = useMemo(() => {
        if (!isTyping) return null;
        return (
            [...messages].reverse().find((m) => m.role === 'assistant')?.id ??
            null
        );
    }, [messages, isTyping]);

    const showEmptyState = useMemo(
        () => messages.length === 0 && !isTyping,
        [messages.length, isTyping],
    );

    const latestAssistantMessage = useMemo(() => {
        return (
            [...messages]
                .reverse()
                .find((message) => message.role === 'assistant') ?? null
        );
    }, [messages]);

    useEffect(() => {
        if (isTyping || reportPickerOpen) {
            setAssistantOptionPicker(null);
            return;
        }

        const choice = deriveAssistantChoice(latestAssistantMessage);
        if (!choice || !latestAssistantMessage) {
            setAssistantOptionPicker(null);
            return;
        }

        setAssistantOptionPicker((previous) => {
            if (previous?.sourceMessageId === latestAssistantMessage.id) {
                return previous;
            }

            return {
                ...choice,
                sourceMessageId: latestAssistantMessage.id,
            };
        });
        setAssistantOptionCustomInput('');
    }, [isTyping, reportPickerOpen, latestAssistantMessage]);

    useEffect(() => {
        setMessages(initialMessages);
        setConversationId(initialConversationId);
    }, [initialConversationId, initialMessages]);

    useEffect(() => {
        return () => {
            abortController?.abort();
        };
    }, [abortController]);

    useEffect(() => {
        return router.on('before', () => {
            if (!isTyping || !abortController) {
                return;
            }
            setIsStopping(true);
            abortController.abort();
        });
    }, [abortController, isTyping]);

    const pushToast = (toast: Omit<Toast, 'id'>) => {
        const id = `toast-${Date.now()}-${Math.random().toString(16).slice(2)}`;
        setToasts((prev) => [...prev, { ...toast, id }]);
        window.setTimeout(
            () => setToasts((prev) => prev.filter((t) => t.id !== id)),
            3200,
        );
    };

    const updateAssistantField = (
        assistantId: string,
        updater: (msg: ChatMessage) => Partial<ChatMessage>,
    ) => {
        setMessages((prev) =>
            prev.map((m) =>
                m.id === assistantId ? { ...m, ...updater(m) } : m,
            ),
        );
    };

    const appendAgentActivity = (
        assistantId: string,
        item: Omit<AgentActivityItem, 'id'>,
    ) => {
        updateAssistantField(assistantId, (m) => ({
            activity: [
                ...(m.activity ?? []),
                {
                    ...item,
                    id: `act-${Date.now()}-${Math.random().toString(16).slice(2)}`,
                },
            ],
        }));
    };

    const onConfirmTask = async (taskId: string) => {
        if (confirmingTaskId) return;
        setConfirmingTaskId(taskId);
        try {
            const xsrfToken = getXsrfTokenFromCookie();
            const csrfToken = getCsrfToken();
            const csrfHeaders: Record<string, string> = xsrfToken
                ? { 'X-XSRF-TOKEN': xsrfToken }
                : csrfToken
                  ? { 'X-CSRF-TOKEN': csrfToken }
                  : {};
            const response = await fetch(`/report-tasks/${taskId}/confirm`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...csrfHeaders,
                },
                body: JSON.stringify({}),
            });

            if (!response.ok) {
                const fallback = await response.text();
                throw new Error(fallback || 'Failed to confirm task.');
            }

            const result = (await response.json()) as ToolResult;
            setMessages((prev) => [
                ...prev,
                {
                    id: `assistant-task-${Date.now()}`,
                    role: 'assistant',
                    content: '',
                    toolCalls: [],
                    toolResults: [result],
                    activity: [],
                },
            ]);
            pushToast({
                type: 'success',
                title: 'Task advanced',
                description: 'Workflow moved to the next step.',
            });
        } catch (error) {
            pushToast({
                type: 'error',
                title: 'Task confirmation failed',
                description:
                    error instanceof Error
                        ? error.message
                        : 'Unexpected error while confirming task.',
            });
        } finally {
            setConfirmingTaskId(null);
        }
    };

    const submitPrompt = async (prompt: string) => {
        if (!prompt || isTyping) return;

        const userMessage: ChatMessage = {
            id: `user-${Date.now()}`,
            role: 'user',
            content: prompt,
        };
        const assistantId = `assistant-${Date.now()}`;
        const nextMessages: ChatMessage[] = [
            ...messages,
            userMessage,
            {
                id: assistantId,
                role: 'assistant',
                content: 'Thinking...',
                thinking: '',
                toolCalls: [],
                toolResults: [],
                activity: [],
            },
        ];

        setMessages(nextMessages);
        setInput('');
        setIsTyping(true);
        setIsStopping(false);

        try {
            const controller = new AbortController();
            setAbortController(controller);
            const xsrfToken = getXsrfTokenFromCookie();
            const csrfToken = getCsrfToken();
            const csrfHeaders: Record<string, string> = xsrfToken
                ? { 'X-XSRF-TOKEN': xsrfToken }
                : csrfToken
                  ? { 'X-CSRF-TOKEN': csrfToken }
                  : {};
            const payloadMessages = nextMessages
                .filter((m) => m.role !== 'assistant' || m.id !== assistantId)
                .map((m) => ({
                    role: m.role,
                    content: m.content.slice(0, 4000),
                }))
                .filter((m) => m.content.trim().length > 0)
                .slice(-40);

            const response = await fetch('/chat/stream', {
                method: 'POST',
                credentials: 'same-origin',
                signal: controller.signal,
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/x-ndjson',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...csrfHeaders,
                },
                body: JSON.stringify({
                    messages: payloadMessages,
                    conversation_id: conversationId,
                }),
            });

            const responseConversationId =
                response.headers.get('X-Conversation-Id');
            if (responseConversationId) {
                setConversationId(responseConversationId);
            }

            if (!response.ok || !response.body) {
                const fallback = await response.text();
                throw new Error(
                    fallback || 'Failed to start streaming response.',
                );
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let rawBuffer = '';
            let carry = '';
            let answer = '';
            let thinking = '';
            let mode: 'answer' | 'thinking' = 'answer';
            let pendingAnswerChars = '';
            let animatorRunning = false;
            let hasThinkingStream = false;
            let receivedToolResult = false;
            let latestContextUsage: ContextUsage | null = null;
            const pendingClientTasks: Array<{
                payload: Record<string, unknown>;
                chatMessageId: string;
            }> = [];
            const toolResultTypes = new Set([
                'orders_table',
                'order_detail',
                'order_created',
                'order_updated',
                'financial_report',
                'merchant_report',
                'call_center_daily_report',
                'call_center_monthly_report',
                'integration_requirements',
                'integration_setup',
                'whatsapp_message_sent',
                'task_created',
                'task_workflow',
                'report_delivery_workflow',
            ]);

            const popTrailingTagPrefix = (
                value: string,
                tag: string,
            ): { text: string; carry: string } => {
                const maxPrefixLength = Math.min(tag.length - 1, value.length);
                for (let length = maxPrefixLength; length > 0; length -= 1) {
                    const suffix = value.slice(-length);
                    if (tag.startsWith(suffix))
                        return { text: value.slice(0, -length), carry: suffix };
                }
                return { text: value, carry: '' };
            };

            const sleep = (ms: number) =>
                new Promise((resolve) => window.setTimeout(resolve, ms));

            const updateAssistantMessage = (includeThinking = false) => {
                updateAssistantField(assistantId, (m) => ({
                    content: answer.trim(),
                    thinking: includeThinking
                        ? thinking.trim()
                        : (m.thinking ?? ''),
                }));
            };

            const consumeDelta = (delta: string) => {
                let chunk = carry + delta;
                carry = '';
                while (chunk.length > 0) {
                    if (mode === 'answer') {
                        const openTagIndex = chunk.indexOf('<think>');
                        if (openTagIndex === -1) {
                            const { text, carry: nextCarry } =
                                popTrailingTagPrefix(chunk, '<think>');
                            answer += text;
                            carry = nextCarry;
                            chunk = '';
                        } else {
                            answer += chunk.slice(0, openTagIndex);
                            chunk = chunk.slice(
                                openTagIndex + '<think>'.length,
                            );
                            mode = 'thinking';
                        }
                    } else {
                        const closeTagIndex = chunk.indexOf('</think>');
                        if (closeTagIndex === -1) {
                            const { text, carry: nextCarry } =
                                popTrailingTagPrefix(chunk, '</think>');
                            thinking += text;
                            carry = nextCarry;
                            chunk = '';
                        } else {
                            thinking += chunk.slice(0, closeTagIndex);
                            chunk = chunk.slice(
                                closeTagIndex + '</think>'.length,
                            );
                            mode = 'answer';
                        }
                    }
                }
                if (!hasThinkingStream) {
                    const t1 = splitTerminalThinking(answer, thinking);
                    const t2 = splitHeuristicThinking(t1.answer, t1.thinking);
                    answer = t2.answer;
                    thinking = t2.thinking;
                }
                updateAssistantMessage();
            };

            const drainCharQueue = async () => {
                if (animatorRunning) return;
                animatorRunning = true;
                while (pendingAnswerChars.length > 0) {
                    consumeDelta(pendingAnswerChars);
                    pendingAnswerChars = '';
                    await Promise.resolve();
                }
                animatorRunning = false;
            };

            // ── Read stream ─────────────────────────────────────────────────
            while (true) {
                const { value, done } = await reader.read();
                if (done) break;

                rawBuffer += decoder.decode(value, { stream: true });
                let newlineIndex = rawBuffer.indexOf('\n');

                while (newlineIndex !== -1) {
                    const line = rawBuffer.slice(0, newlineIndex).trim();
                    rawBuffer = rawBuffer.slice(newlineIndex + 1);

                    if (line !== '') {
                        let event: Record<string, unknown>;
                        try {
                            event = JSON.parse(line);
                        } catch {
                            newlineIndex = rawBuffer.indexOf('\n');
                            continue;
                        }

                        // ── Regular text delta ───────────────────────────────
                        if (
                            event['type'] === 'delta' &&
                            typeof event['content'] === 'string'
                        ) {
                            pendingAnswerChars += event['content'];
                            void drainCharQueue();
                        }

                        if (event['type'] === 'status') {
                            const phase = String(
                                event['phase'] ?? '',
                            ).toLowerCase();
                            const statusText = '';
                            const phaseLabel =
                                phase === 'planning'
                                    ? 'Planning'
                                    : phase === 'executing'
                                      ? 'Executing'
                                      : 'Processing';
                            if (statusText) {
                                updateAssistantField(assistantId, (m) => ({
                                    content:
                                        m.content.trim() === '' ||
                                        m.content === 'Thinking...'
                                            ? statusText
                                            : m.content,
                                }));
                            }
                            appendAgentActivity(assistantId, {
                                kind: 'status',
                                title: `${phaseLabel} phase`,
                                detail:
                                    statusText || 'Working on your request.',
                                state:
                                    phase === 'executing'
                                        ? 'running'
                                        : 'completed',
                            });
                        }

                        if (
                            event['type'] === 'plan' &&
                            typeof event['plan'] === 'object' &&
                            event['plan'] !== null
                        ) {
                            const plan = event['plan'] as Record<
                                string,
                                unknown
                            >;
                            const goal =
                                typeof plan.goal === 'string' ? plan.goal : '';
                            appendAgentActivity(assistantId, {
                                kind: 'note',
                                title: 'Execution plan created',
                                detail: goal ? `Goal: ${goal}` : undefined,
                                state: 'completed',
                            });

                            const steps = Array.isArray(plan.steps)
                                ? plan.steps
                                : [];
                            steps.forEach((rawStep, index) => {
                                if (!rawStep || typeof rawStep !== 'object')
                                    return;
                                const step = rawStep as Record<string, unknown>;
                                const stepNo = Number(step.step);
                                const action =
                                    typeof step.action === 'string'
                                        ? step.action
                                        : 'Planned action';
                                const tool =
                                    typeof step.tool === 'string'
                                        ? step.tool
                                        : 'none';
                                const risk =
                                    typeof step.risk === 'string'
                                        ? step.risk
                                        : 'medium';
                                appendAgentActivity(assistantId, {
                                    kind: 'plan_step',
                                    title: `Step ${Number.isFinite(stepNo) && stepNo > 0 ? stepNo : index + 1}: ${action}`,
                                    detail: `Tool: ${tool} | Risk: ${risk}`,
                                    state: 'completed',
                                });
                            });
                        }

                        // ── Thinking delta ───────────────────────────────────
                        if (
                            event['type'] === 'thinking_delta' &&
                            typeof event['content'] === 'string'
                        ) {
                            hasThinkingStream = true;
                            thinking += event['content'];
                        }

                        if (event['type'] === 'context_usage') {
                            const promptEvalCount = Number(
                                event['prompt_eval_count'],
                            );
                            const evalCount = Number(event['eval_count']);
                            const contextWindow = Number(
                                event['context_window'],
                            );
                            const contextUsedPct = Number(
                                event['context_used_pct'],
                            );
                            const contextRemaining = Number(
                                event['context_remaining'],
                            );
                            const iterationValue = Number(event['iteration']);

                            if (
                                Number.isFinite(promptEvalCount) &&
                                Number.isFinite(evalCount) &&
                                Number.isFinite(contextWindow) &&
                                Number.isFinite(contextUsedPct) &&
                                Number.isFinite(contextRemaining)
                            ) {
                                latestContextUsage = {
                                    prompt_eval_count: promptEvalCount,
                                    eval_count: evalCount,
                                    context_window: contextWindow,
                                    context_used_pct: contextUsedPct,
                                    context_remaining: contextRemaining,
                                    iteration: Number.isFinite(iterationValue)
                                        ? iterationValue
                                        : undefined,
                                };
                                setContextUsage(latestContextUsage);
                            }
                        }

                        // ── Tool call in progress ────────────────────────────
                        if (event['type'] === 'tool_call') {
                            const toolCall: ToolCall = {
                                tool: String(event['tool'] ?? ''),
                                args:
                                    (event['args'] as Record<
                                        string,
                                        unknown
                                    >) ?? {},
                                status: 'running',
                            };
                            updateAssistantField(assistantId, (m) => ({
                                toolCalls: [...(m.toolCalls ?? []), toolCall],
                            }));
                            appendAgentActivity(assistantId, {
                                kind: 'tool_call',
                                title: `Calling tool: ${toolCall.tool || 'unknown'}`,
                                detail: shortJson(toolCall.args),
                                tool: toolCall.tool,
                                state: 'running',
                            });

                            if (toolCall.tool === 'create_task') {
                                pendingClientTasks.push({
                                    payload: toolCall.args,
                                    chatMessageId: assistantId,
                                });
                            }
                        }

                        // ── Tool result (structured data) ─────────────────────
                        const eventType = String(event['type'] ?? '');
                        const isToolResultError =
                            eventType === 'error' &&
                            !Object.prototype.hasOwnProperty.call(
                                event,
                                'details',
                            ) &&
                            !Object.prototype.hasOwnProperty.call(
                                event,
                                'upstream_status',
                            );
                        const isToolResult =
                            eventType === 'tool_result' ||
                            toolResultTypes.has(eventType) ||
                            isToolResultError;

                        if (isToolResult) {
                            receivedToolResult = true;
                            const wrappedResult = event['result'];
                            const toolName = String(event['tool'] ?? '');
                            const result = (
                                eventType === 'tool_result' &&
                                typeof wrappedResult === 'object' &&
                                wrappedResult !== null
                                    ? wrappedResult
                                    : event
                            ) as ToolResult;
                            const resultType = String(
                                (result as { type?: string }).type ?? '',
                            );
                            const failed = resultType === 'error';

                            updateAssistantField(assistantId, (m) => ({
                                toolResults: [...(m.toolResults ?? []), result],
                                toolCalls: (() => {
                                    const current = [...(m.toolCalls ?? [])];
                                    for (
                                        let i = current.length - 1;
                                        i >= 0;
                                        i -= 1
                                    ) {
                                        const item = current[i];
                                        if (
                                            item.tool === toolName &&
                                            (item.status ?? 'running') ===
                                                'running'
                                        ) {
                                            current[i] = {
                                                ...item,
                                                status: failed
                                                    ? 'failed'
                                                    : 'completed',
                                                resultType,
                                            };
                                            break;
                                        }
                                    }
                                    return current;
                                })(),
                            }));
                            appendAgentActivity(assistantId, {
                                kind: 'tool_result',
                                title: `${failed ? 'Tool failed' : 'Tool completed'}: ${toolName || 'unknown'}`,
                                detail: `Result type: ${resultType || 'unknown'}`,
                                tool: toolName,
                                state: failed ? 'failed' : 'completed',
                            });

                            if (
                                toolName === 'create_task' &&
                                pendingClientTasks.length > 0
                            ) {
                                pendingClientTasks.shift();
                            }

                            if (result.type === 'task_created') {
                                pushToast({
                                    type: 'success',
                                    title: 'Task created',
                                    description: result.task_url
                                        ? `Track it at ${result.task_url}`
                                        : (result.message ?? result.title),
                                });
                            }
                        }

                        if (event['type'] === 'critic') {
                            const tool = String(event['tool'] ?? 'unknown');
                            const ok = Boolean(event['ok']);
                            const severity = String(event['severity'] ?? 'low');
                            const issues = Array.isArray(event['issues'])
                                ? event['issues'].map(String)
                                : [];
                            appendAgentActivity(assistantId, {
                                kind: 'critic',
                                title: `Quality check (${tool})`,
                                detail:
                                    issues.length > 0
                                        ? issues.join('\n')
                                        : `No issues detected (severity: ${severity}).`,
                                tool,
                                state: ok ? 'completed' : 'failed',
                            });
                        }

                        // ── Error ─────────────────────────────────────────────
                        if (eventType === 'error') {
                            const msg = [event['message'], event['details']]
                                .filter(Boolean)
                                .join(' ');
                            throw new Error(
                                `${msg || 'Streaming error.'}${event['upstream_status'] ? ` (upstream: ${event['upstream_status']})` : ''}`,
                            );
                        }
                    }

                    newlineIndex = rawBuffer.indexOf('\n');
                }
            }

            // Drain remaining chars
            while (pendingAnswerChars.length > 0 || animatorRunning)
                await sleep(8);

            if (!hasThinkingStream) {
                const t1 = splitTerminalThinking(answer, thinking);
                const t2 = splitHeuristicThinking(t1.answer, t1.thinking);
                answer = t2.answer;
                thinking = t2.thinking;
            }

            if (answer.trim() === '') {
                answer = receivedToolResult
                    ? 'I completed the request. Please review the tool results above.'
                    : 'I could not generate a visible response this time. Please retry.';
            }

            updateAssistantField(assistantId, () => ({
                content: answer.trim(),
                thinking: thinking.trim(),
            }));

            for (const pendingTask of pendingClientTasks) {
                if (!isTaskPayload(pendingTask.payload)) {
                    continue;
                }
                const backendTask = await submitTaskToBackend(
                    pendingTask.payload,
                    pendingTask.chatMessageId,
                );

                if (!backendTask) {
                    continue;
                }

                updateAssistantField(assistantId, (m) => ({
                    toolResults: [
                        ...(m.toolResults ?? []),
                        {
                            type: 'task_created',
                            id: backendTask.task.id,
                            title: backendTask.task.title,
                            status: 'queued',
                            schedule_type: 'immediate',
                            task_url: `/tasks/${backendTask.task.id}`,
                            message: backendTask.message,
                        },
                    ],
                }));

                pushToast({
                    type: 'success',
                    title: 'Task created',
                    description: `Track it at /tasks/${backendTask.task.id}`,
                });
            }

            if (latestContextUsage) {
                const pct = latestContextUsage.context_used_pct.toFixed(2);
                pushToast({
                    type:
                        latestContextUsage.context_used_pct >= 85
                            ? 'error'
                            : 'info',
                    title: 'Context usage',
                    description: `Prompt: ${latestContextUsage.prompt_eval_count.toLocaleString()} / ${latestContextUsage.context_window.toLocaleString()} tokens (${pct}%)`,
                });
            }

            pushToast({
                type: 'success',
                title: 'Response complete',
                description: 'Streaming finished successfully.',
            });
        } catch (requestError) {
            const wasAborted =
                requestError instanceof DOMException &&
                requestError.name === 'AbortError';
            if (wasAborted) {
                updateAssistantField(assistantId, (m) => ({
                    content:
                        m.content.trim().length > 0
                            ? m.content
                            : 'Response stopped.',
                    thinking: '',
                }));
                pushToast({
                    type: 'info',
                    title: 'Response stopped',
                    description: 'Generation was canceled.',
                });
                return;
            }

            pushToast({
                type: 'error',
                title: 'Request failed',
                description:
                    requestError instanceof Error
                        ? requestError.message
                        : 'Failed to get AI response.',
            });
            updateAssistantField(assistantId, () => ({
                content:
                    requestError instanceof Error
                        ? `I hit an error while processing your request: ${requestError.message}`
                        : 'I hit an error while processing your request. Please retry.',
                thinking: '',
            }));
        } finally {
            setIsTyping(false);
            setIsStopping(false);
            setAbortController(null);
        }
    };

    const submitCurrentInput = async () => {
        const prompt = input.trim();
        if (!prompt || isTyping) return;

        if (shouldPromptForReportType(prompt)) {
            setPendingReportPrompt(prompt);
            setReportCustomInput('');
            setReportPickerOpen(true);
            return;
        }

        await submitPrompt(prompt);
    };

    const onSubmit = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        await submitCurrentInput();
    };

    const onTextareaKeyDown = async (
        event: KeyboardEvent<HTMLTextAreaElement>,
    ) => {
        if ((event.nativeEvent as { isComposing?: boolean }).isComposing) {
            return;
        }
        if (event.key !== 'Enter') return;
        if (event.shiftKey) return;

        event.preventDefault();
        await submitCurrentInput();
    };

    const onStopResponse = () => {
        if (!isTyping || !abortController) return;
        setIsStopping(true);
        abortController.abort();
    };

    const onSelectReportType = async (reportType: ReportTypeOption) => {
        if (!pendingReportPrompt || isTyping) return;

        const refinedPrompt = buildReportSelectionPrompt(
            pendingReportPrompt,
            reportType,
            reportCustomInput,
        );

        setReportPickerOpen(false);
        setPendingReportPrompt(null);
        setReportCustomInput('');
        await submitPrompt(refinedPrompt);
    };

    const onSubmitCustomReport = async () => {
        const custom = reportCustomInput.trim();
        if (!pendingReportPrompt || isTyping || custom === '') return;

        const refinedPrompt = `${pendingReportPrompt}\n\nSelected report type: custom.\nCustom report request: ${custom}`;
        setReportPickerOpen(false);
        setPendingReportPrompt(null);
        setReportCustomInput('');
        await submitPrompt(refinedPrompt);
    };

    const onSelectAssistantOption = async (option: string) => {
        const selected = option.trim();
        if (!assistantOptionPicker || isTyping || selected === '') return;

        setAssistantOptionCustomInput('');
        await submitPrompt(
            `For your question "${assistantOptionPicker.question}", I choose: ${selected}. Proceed with this option.`,
        );
    };

    const onSubmitCustomAssistantOption = async () => {
        const custom = assistantOptionCustomInput.trim();
        if (!assistantOptionPicker || isTyping || custom === '') return;

        await onSelectAssistantOption(custom);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="AI Chat" />

            {/* Toast notifications */}
            <div className="fixed top-20 right-6 z-50 flex w-80 flex-col gap-2">
                {toasts.map((toast) => (
                    <Alert
                        key={toast.id}
                        variant={
                            toast.type === 'error' ? 'destructive' : 'default'
                        }
                        className="border shadow-sm"
                    >
                        {toast.type === 'error' && <AlertCircle />}
                        {toast.type === 'success' && (
                            <CheckCircle2 className="text-emerald-600" />
                        )}
                        {toast.type === 'info' && (
                            <Sparkles className="text-violet-600" />
                        )}
                        <AlertTitle>{toast.title}</AlertTitle>
                        {toast.description && (
                            <AlertDescription>
                                {toast.description}
                            </AlertDescription>
                        )}
                    </Alert>
                ))}
            </div>

            <div className="flex h-[calc(100svh-4rem)] min-h-0 flex-1 overflow-hidden">
                <Card className="relative h-full min-h-0 w-full gap-0 overflow-hidden rounded-none border-x-0 border-b-0 py-0">
                    {contextUsage &&
                        (() => {
                            const usage = Math.min(
                                100,
                                Math.max(0, contextUsage.context_used_pct),
                            );
                            const barClass =
                                usage >= 90
                                    ? 'bg-red-500'
                                    : usage >= 75
                                      ? 'bg-amber-500'
                                      : 'bg-emerald-500';
                            const textClass =
                                usage >= 90
                                    ? 'text-red-700'
                                    : usage >= 75
                                      ? 'text-amber-700'
                                      : 'text-emerald-700';

                            return (
                                <div className="border-b border-slate-200 bg-white px-4 py-2 sm:px-6">
                                    <div className="mb-1.5 flex items-center justify-between text-xs">
                                        <div className="inline-flex items-center gap-1.5 text-slate-700">
                                            <Gauge className="h-3.5 w-3.5" />
                                            <span className="font-medium">
                                                Context Window
                                            </span>
                                        </div>
                                        <div
                                            className={`font-semibold ${textClass}`}
                                        >
                                            {usage.toFixed(2)}%
                                        </div>
                                    </div>
                                    <div className="h-2 overflow-hidden rounded-full bg-slate-100">
                                        <div
                                            className={`h-full transition-all duration-300 ${barClass}`}
                                            style={{ width: `${usage}%` }}
                                        />
                                    </div>
                                    <div className="mt-1.5 flex items-center justify-between text-[11px] text-slate-600">
                                        <span>
                                            Used:{' '}
                                            {contextUsage.prompt_eval_count.toLocaleString()}{' '}
                                            /{' '}
                                            {contextUsage.context_window.toLocaleString()}{' '}
                                            tokens
                                        </span>
                                        <span>
                                            Left:{' '}
                                            {contextUsage.context_remaining.toLocaleString()}
                                        </span>
                                    </div>
                                </div>
                            );
                        })()}

                    {/* Message list */}
                    <div className="min-h-0 flex-1 overflow-y-auto bg-slate-50 px-4 py-4 pb-40 sm:px-6">
                        {showEmptyState ? (
                            <div className="flex min-h-full flex-col items-center justify-center bg-gradient-to-b from-slate-50 via-slate-50 to-slate-100/60 text-center">
                                <p className="mb-2 text-sm text-slate-500">
                                    Orders management assistant
                                </p>
                                <h1 className="text-4xl font-semibold tracking-tight text-slate-900 sm:text-5xl">
                                    How can I help you today?
                                </h1>
                                <p className="mt-3 text-sm text-slate-400">
                                    Ask me to list, search, create, or update
                                    orders.
                                </p>
                            </div>
                        ) : (
                            <div className="space-y-5">
                                {messages.map((message) => {
                                    const hasFinancialReportResult =
                                        message.role === 'assistant' &&
                                        (message.toolResults ?? []).some(
                                            (result) =>
                                                result.type ===
                                                'financial_report',
                                        );
                                    const hasAgentActivity =
                                        message.role === 'assistant' &&
                                        ((message.activity ?? []).length > 0 ||
                                            Boolean(message.thinking) ||
                                            (message.toolCalls ?? []).length >
                                                0);

                                    return (
                                        <div
                                            key={message.id}
                                            className={
                                                message.role === 'user'
                                                    ? 'ml-auto max-w-[80%]'
                                                    : 'max-w-full'
                                            }
                                        >
                                            {/* Avatar + name row */}
                                            <div
                                                className={`mb-1 flex items-center gap-2 text-xs text-slate-500 ${message.role === 'user' ? 'justify-end' : 'justify-start'}`}
                                            >
                                                {message.role !== 'user' && (
                                                    <Avatar className="h-6 w-6">
                                                        <AvatarFallback>
                                                            AI
                                                        </AvatarFallback>
                                                    </Avatar>
                                                )}
                                                <span>
                                                    {message.role === 'user'
                                                        ? 'You'
                                                        : 'Assistant'}{' '}
                                                    • now
                                                </span>
                                            </div>

                                            {/* Bubble */}
                                            <div
                                                className={`rounded-xl border px-3 py-2 text-sm ${message.role === 'user' ? 'border-blue-100 bg-blue-50 text-slate-800' : 'border-slate-200 bg-white text-slate-800'}`}
                                            >
                                                {hasAgentActivity && (
                                                    <div className="mb-2 rounded-lg border border-slate-200 bg-slate-50 p-3">
                                                        {isTyping &&
                                                            message.id ===
                                                                activeAssistantId && (
                                                                <div className="mb-2 inline-flex items-center rounded-full border border-slate-200 bg-white px-2.5 py-1 text-xs text-slate-700">
                                                                    <span className="inline-block animate-bounce text-base">
                                                                        🧠
                                                                    </span>
                                                                </div>
                                                            )}
                                                        {message.thinking && (
                                                            <div className="mb-3 rounded-md border border-violet-200 bg-violet-50 p-2 text-xs text-violet-900">
                                                                <p className="mb-1 font-semibold">
                                                                    Thinking
                                                                </p>
                                                                <div className="max-h-40 overflow-y-auto">
                                                                    {renderRichText(
                                                                        message.thinking,
                                                                    )}
                                                                </div>
                                                            </div>
                                                        )}
                                                        <AgentActivityTimeline
                                                            items={
                                                                message.activity ??
                                                                []
                                                            }
                                                        />
                                                    </div>
                                                )}

                                                {/* Tool calls in progress */}
                                                {message.role === 'assistant' &&
                                                    (message.toolCalls ?? [])
                                                        .length > 0 && (
                                                        <div className="mb-2 flex flex-wrap gap-1.5">
                                                            {message.toolCalls!.map(
                                                                (tc, i) => (
                                                                    <ToolCallBadge
                                                                        key={i}
                                                                        toolCall={
                                                                            tc
                                                                        }
                                                                    />
                                                                ),
                                                            )}
                                                        </div>
                                                    )}

                                                {/* Tool results (tables, cards, etc.) */}
                                                {message.role === 'assistant' &&
                                                    (message.toolResults ?? [])
                                                        .length > 0 && (
                                                        <div className="mb-2">
                                                            <ToolResultsView
                                                                results={
                                                                    message.toolResults!
                                                                }
                                                                onViewOrder={
                                                                    setSelectedOrder
                                                                }
                                                                onConfirmTask={
                                                                    onConfirmTask
                                                                }
                                                                confirmingTaskId={
                                                                    confirmingTaskId
                                                                }
                                                            />
                                                            {isTyping &&
                                                                message.id ===
                                                                    activeAssistantId &&
                                                                !message.content && (
                                                                    <div className="mt-2 inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs text-amber-700">
                                                                        <span className="inline-block animate-bounce text-base">
                                                                            🧠
                                                                        </span>
                                                                    </div>
                                                                )}
                                                        </div>
                                                    )}

                                                {/* Text content */}
                                                {message.content &&
                                                    !isThinkingPlaceholderContent(
                                                        message.content,
                                                    ) && (
                                                    <div className="space-y-1">
                                                        {hasFinancialReportResult ? (
                                                            <p className="text-xs text-slate-500">
                                                                Report details
                                                                are shown in the
                                                                structured table
                                                                above.
                                                            </p>
                                                        ) : (
                                                            <>
                                                                {renderRichText(
                                                                    message.content,
                                                                )}
                                                                {isTyping &&
                                                                    message.id ===
                                                                        activeAssistantId && (
                                                                        <span className="ml-0.5 inline-block h-4 w-1 animate-pulse rounded-sm bg-slate-400 align-middle" />
                                                                    )}
                                                            </>
                                                        )}
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    );
                                })}

                                {/* Typing / waiting indicator */}
                                {waitingForFirstResponseToken && (
                                    <div className="flex justify-center pt-2">
                                        <div className="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs text-slate-600 shadow-sm">
                                            <span className="inline-block animate-bounce text-base">
                                                🧠
                                            </span>
                                        </div>
                                    </div>
                                )}

                                {waitingForPostToolNarration && (
                                    <div className="flex justify-center pt-2">
                                        <div className="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs text-amber-700 shadow-sm">
                                            <span className="inline-block animate-bounce text-base">
                                                🧠
                                            </span>
                                        </div>
                                    </div>
                                )}
                            </div>
                        )}
                    </div>

                    {/* Input form */}
                    <form
                        onSubmit={onSubmit}
                        className="fixed inset-x-0 bottom-4 z-40 px-4"
                    >
                        <div className="mx-auto w-full max-w-4xl">
                            {reportPickerOpen && (
                                <div className="mb-2 rounded-xl border border-slate-200 bg-white p-3 shadow-lg">
                                    <p className="text-sm font-semibold text-slate-900">
                                        Select report type
                                    </p>
                                    <p className="mt-0.5 text-xs text-slate-600">
                                        Choose one option. Popup closes and AI
                                        continues automatically.
                                    </p>
                                    <div className="mt-2 grid gap-2 sm:grid-cols-3">
                                        <Button
                                            type="button"
                                            onClick={() =>
                                                void onSelectReportType(
                                                    'financial',
                                                )
                                            }
                                            className="justify-center"
                                        >
                                            Financial
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() =>
                                                void onSelectReportType(
                                                    'merchant',
                                                )
                                            }
                                            className="justify-center"
                                        >
                                            Merchant
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() =>
                                                void onSelectReportType(
                                                    'call_center',
                                                )
                                            }
                                            className="justify-center"
                                        >
                                            Call Center
                                        </Button>
                                    </div>

                                    <div className="mt-3 border-t border-slate-200 pt-3">
                                        <p className="text-xs font-semibold uppercase tracking-wide text-slate-700">
                                            Other / refine
                                        </p>
                                        <p className="mt-0.5 text-xs text-slate-600">
                                            Type your own report direction, or
                                            enhance one of the options above.
                                        </p>
                                        <Input
                                            value={reportCustomInput}
                                            onChange={(event) =>
                                                setReportCustomInput(
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="e.g. Weekly merchant report for Nairobi with instruction analysis"
                                            className="mt-2 h-10"
                                        />
                                        <div className="mt-2 flex justify-end">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                disabled={
                                                    reportCustomInput.trim() ===
                                                    ''
                                                }
                                                onClick={() =>
                                                    void onSubmitCustomReport()
                                                }
                                            >
                                                Use Custom Request
                                            </Button>
                                        </div>
                                    </div>
                                </div>
                            )}

                            {assistantOptionPicker && (
                                <div className="mb-2 max-h-[45vh] overflow-y-auto rounded-xl border border-slate-200 bg-white p-3 shadow-lg">
                                    <div className="mb-1 flex items-start justify-between gap-2">
                                        <p className="text-sm font-semibold text-slate-900">
                                            {assistantOptionPicker.question}
                                        </p>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="h-7 w-7 shrink-0"
                                            onClick={() => {
                                                setAssistantOptionPicker(null);
                                                setAssistantOptionCustomInput('');
                                            }}
                                        >
                                            <X className="h-4 w-4" />
                                        </Button>
                                    </div>
                                    <p className="mt-0.5 text-xs text-slate-600">
                                        Choose one option. Popup closes and AI
                                        continues automatically.
                                    </p>
                                    <div className="mt-2 grid gap-2 sm:grid-cols-2">
                                        {assistantOptionPicker.options.map(
                                            (option, index) => (
                                                <Button
                                                    key={`${option}-${index}`}
                                                    type="button"
                                                    variant={
                                                        index === 0
                                                            ? 'default'
                                                            : 'outline'
                                                    }
                                                    onClick={() =>
                                                        void onSelectAssistantOption(
                                                            option,
                                                        )
                                                    }
                                                    className="h-auto min-h-10 justify-start text-left whitespace-normal break-words"
                                                >
                                                    {option}
                                                </Button>
                                            ),
                                        )}
                                    </div>

                                    <div className="mt-3 border-t border-slate-200 pt-3">
                                        <p className="text-xs font-semibold uppercase tracking-wide text-slate-700">
                                            Other option
                                        </p>
                                        <Input
                                            value={assistantOptionCustomInput}
                                            onChange={(event) =>
                                                setAssistantOptionCustomInput(
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="Type your option"
                                            className="mt-2 h-10"
                                        />
                                        <div className="mt-2 flex justify-end">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                disabled={
                                                    assistantOptionCustomInput.trim() ===
                                                    ''
                                                }
                                                onClick={() =>
                                                    void onSubmitCustomAssistantOption()
                                                }
                                            >
                                                Use Custom Option
                                            </Button>
                                        </div>
                                    </div>
                                </div>
                            )}

                            <div className="rounded-2xl border bg-white/95 shadow-lg backdrop-blur">
                            <CardContent className="pt-3">
                                <textarea
                                    value={input}
                                    onChange={(e) => setInput(e.target.value)}
                                    onKeyDown={(event) =>
                                        void onTextareaKeyDown(event)
                                    }
                                    placeholder="Ask about orders, or say 'create an order for…'"
                                    autoComplete="off"
                                    rows={3}
                                    className="w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none transition-[color,box-shadow] placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50"
                                />
                            </CardContent>
                            <CardFooter className="justify-between py-2">
                                <div className="flex items-center gap-2">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                    >
                                        <Paperclip className="h-4 w-4" />
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                    >
                                        <Smile className="h-4 w-4" />
                                    </Button>
                                    <Badge
                                        variant="secondary"
                                        className="gap-1"
                                    >
                                        <Link className="h-3 w-3" />
                                        Orders DB
                                    </Badge>
                                </div>
                                <div className="flex items-center gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        className="hidden sm:inline-flex"
                                    >
                                        <Plus className="h-4 w-4" />
                                    </Button>
                                    {isTyping ? (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="destructive"
                                            onClick={onStopResponse}
                                            disabled={isStopping}
                                            className="gap-1"
                                        >
                                            Stop <Square className="h-4 w-4" />
                                        </Button>
                                    ) : (
                                        <Button
                                            type="submit"
                                            size="sm"
                                            disabled={!canSend}
                                            className="gap-1"
                                        >
                                            Send{' '}
                                            <SendHorizontal className="h-4 w-4" />
                                        </Button>
                                    )}
                                </div>
                            </CardFooter>
                            </div>
                        </div>
                    </form>
                </Card>
            </div>

            <OrderDetailsDialog
                order={selectedOrder}
                open={selectedOrder !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setSelectedOrder(null);
                    }
                }}
            />

        </AppLayout>
    );
}
