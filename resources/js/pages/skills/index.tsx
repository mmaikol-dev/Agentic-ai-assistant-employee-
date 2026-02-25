import { Head, router, usePage } from '@inertiajs/react';
import { BookText, BrainCircuit, Hash, Sparkles, Tags } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

type SkillRecord = {
    key: string;
    label: string;
    path: string;
    content: string;
    updated_at: string;
};

type Props = {
    skills: SkillRecord[];
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Skills', href: '/skills' }];

type ParsedSkill = {
    name: string;
    description: string;
    triggers: string[];
    sections: string[];
    wordCount: number;
    preview: string;
};

function parseSkill(content: string): ParsedSkill {
    const trimmed = content.trim();
    const frontMatterMatch = trimmed.match(/^---\s*\n([\s\S]*?)\n---\s*\n?([\s\S]*)$/);
    const frontMatter = frontMatterMatch?.[1] ?? '';
    const body = frontMatterMatch?.[2] ?? trimmed;
    const meta: Record<string, string> = {};

    for (const line of frontMatter.split('\n')) {
        const idx = line.indexOf(':');
        if (idx <= 0) continue;
        const key = line.slice(0, idx).trim().toLowerCase();
        const value = line
            .slice(idx + 1)
            .trim()
            .replace(/^['"]|['"]$/g, '');
        if (key) meta[key] = value;
    }

    const sections = Array.from(
        body.matchAll(/^##?\s+(.+)$/gm),
        (match) => match[1].trim(),
    ).filter((heading) => heading !== '');

    const firstParagraph =
        body
            .split(/\n\s*\n/)
            .map((part) => part.trim())
            .find((part) => part !== '' && !part.startsWith('#')) ?? '';

    return {
        name: meta.name || 'Unnamed skill',
        description: meta.description || 'No description provided.',
        triggers:
            meta.triggers
                ?.split(',')
                .map((t) => t.trim())
                .filter((t) => t !== '') ?? [],
        sections,
        wordCount: body.split(/\s+/).filter(Boolean).length,
        preview: firstParagraph.slice(0, 240),
    };
}

export default function SkillsIndex({ skills }: Props) {
    const page = usePage<{ errors?: Record<string, string> }>();
    const errors = page.props.errors ?? {};
    const [query, setQuery] = useState('');
    const [selectedKey, setSelectedKey] = useState<string>(skills[0]?.key ?? '');
    const [editorContent, setEditorContent] = useState(skills[0]?.content ?? '');
    const [isSaving, setIsSaving] = useState(false);
    const [saveMessage, setSaveMessage] = useState<string | null>(null);

    const filteredSkills = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (q === '') return skills;
        return skills.filter((skill) =>
            [skill.label, skill.key, skill.path].some((value) =>
                value.toLowerCase().includes(q),
            ),
        );
    }, [query, skills]);

    const selectedSkill = useMemo(
        () => skills.find((skill) => skill.key === selectedKey) ?? null,
        [skills, selectedKey],
    );
    const parsedSelectedSkill = useMemo(
        () => (selectedSkill ? parseSkill(selectedSkill.content) : null),
        [selectedSkill],
    );

    const isDirty = selectedSkill !== null && editorContent !== selectedSkill.content;

    useEffect(() => {
        if (!selectedSkill && filteredSkills[0]) {
            setSelectedKey(filteredSkills[0].key);
            setEditorContent(filteredSkills[0].content);
        }
    }, [filteredSkills, selectedSkill]);

    const selectSkill = (skill: SkillRecord) => {
        if (isSaving) return;

        if (isDirty) {
            const shouldDiscard = window.confirm(
                'You have unsaved changes. Discard them and switch skills?',
            );
            if (!shouldDiscard) return;
        }

        setSelectedKey(skill.key);
        setEditorContent(skill.content);
        setSaveMessage(null);
    };

    const saveSkill = () => {
        if (!selectedSkill || isSaving || !isDirty) return;

        setIsSaving(true);
        setSaveMessage(null);
        router.post(
            '/skills/update',
            {
                key: selectedSkill.key,
                content: editorContent,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    setSaveMessage('Skill saved.');
                },
                onError: () => {
                    setSaveMessage('Failed to save skill. Check the error below.');
                },
                onFinish: () => {
                    setIsSaving(false);
                },
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Skills" />

            <div className="h-[calc(100svh-6rem)] w-full px-4 py-4">
                <div className="mb-6">
                    <div className="flex items-center gap-2">
                        <Sparkles className="h-5 w-5 text-gray-700" />
                        <h1 className="text-2xl font-semibold text-gray-900">Skills</h1>
                    </div>
                    <p className="mt-1 text-sm text-gray-500">
                        View and edit `SKILL.md` files used by the assistant.
                    </p>
                </div>

                <div className="grid h-[calc(100%-5rem)] min-h-0 grid-cols-[320px_1fr] gap-4">
                    <aside className="flex min-h-0 flex-col rounded-xl border border-gray-200 bg-white p-3">
                        <input
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Search skills..."
                            className="mb-3 w-full rounded-md border border-gray-200 px-3 py-2 text-sm focus:border-gray-400 focus:outline-none"
                        />

                        <div className="min-h-0 flex-1 space-y-1 overflow-y-auto pr-1">
                            {filteredSkills.length === 0 && (
                                <p className="rounded-md border border-dashed border-gray-200 p-3 text-xs text-gray-500">
                                    No skills matched your search.
                                </p>
                            )}

                            {filteredSkills.map((skill) => (
                                <button
                                    key={skill.key}
                                    type="button"
                                    onClick={() => selectSkill(skill)}
                                    className={`w-full rounded-md border px-3 py-2 text-left transition ${
                                        skill.key === selectedKey
                                            ? 'border-gray-900 bg-gray-900 text-white'
                                            : 'border-gray-200 bg-white text-gray-700 hover:border-gray-400'
                                    }`}
                                >
                                    <div className="mb-1 flex items-center gap-1.5">
                                        <BrainCircuit className="h-3.5 w-3.5 shrink-0" />
                                        <p className="truncate text-sm font-medium">{skill.label}</p>
                                    </div>
                                    <p
                                        className={`truncate text-[11px] ${
                                            skill.key === selectedKey
                                                ? 'text-gray-300'
                                                : 'text-gray-500'
                                        }`}
                                    >
                                        {skill.path}
                                    </p>
                                    <p
                                        className={`mt-1 line-clamp-2 text-[11px] ${
                                            skill.key === selectedKey
                                                ? 'text-gray-200'
                                                : 'text-gray-500'
                                        }`}
                                    >
                                        {parseSkill(skill.content).description}
                                    </p>
                                </button>
                            ))}
                        </div>
                    </aside>

                    <section className="min-h-0 rounded-xl border border-slate-200 bg-white p-4">
                        {!selectedSkill ? (
                            <p className="text-sm text-gray-500">Select a skill to start editing.</p>
                        ) : (
                            <div className="flex h-full min-h-0 flex-col">
                                <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <h2 className="text-lg font-medium text-gray-900">{selectedSkill.label}</h2>
                                        <p className="text-xs text-gray-500">{selectedSkill.path}</p>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <span
                                            className={`rounded-full px-2 py-1 text-xs ${
                                                isDirty
                                                    ? 'bg-amber-100 text-amber-700'
                                                    : 'bg-emerald-100 text-emerald-700'
                                            }`}
                                        >
                                            {isDirty ? 'Unsaved changes' : 'Saved'}
                                        </span>
                                        <button
                                            type="button"
                                            onClick={saveSkill}
                                            disabled={!isDirty || isSaving}
                                            className="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white disabled:cursor-not-allowed disabled:opacity-40"
                                        >
                                            {isSaving ? 'Saving...' : 'Save'}
                                        </button>
                                    </div>
                                </div>

                                {parsedSelectedSkill && (
                                    <div className="mb-3 grid gap-2 lg:grid-cols-3">
                                        <div className="rounded-md border border-gray-200 bg-gray-50 p-2">
                                            <p className="mb-1 flex items-center gap-1 text-[11px] font-semibold uppercase tracking-wide text-gray-600">
                                                <BookText className="h-3.5 w-3.5" />
                                                Description
                                            </p>
                                            <p className="text-xs text-gray-700">{parsedSelectedSkill.description}</p>
                                        </div>
                                        <div className="rounded-md border border-gray-200 bg-gray-50 p-2">
                                            <p className="mb-1 flex items-center gap-1 text-[11px] font-semibold uppercase tracking-wide text-gray-600">
                                                <Tags className="h-3.5 w-3.5" />
                                                Triggers
                                            </p>
                                            <div className="flex flex-wrap gap-1">
                                                {parsedSelectedSkill.triggers.slice(0, 6).map((trigger) => (
                                                    <span
                                                        key={trigger}
                                                        className="rounded-full bg-white px-2 py-0.5 text-[10px] text-gray-700 ring-1 ring-gray-200"
                                                    >
                                                        {trigger}
                                                    </span>
                                                ))}
                                                {parsedSelectedSkill.triggers.length === 0 && (
                                                    <span className="text-xs text-gray-500">No triggers</span>
                                                )}
                                            </div>
                                        </div>
                                        <div className="rounded-md border border-gray-200 bg-gray-50 p-2">
                                            <p className="mb-1 flex items-center gap-1 text-[11px] font-semibold uppercase tracking-wide text-gray-600">
                                                <Hash className="h-3.5 w-3.5" />
                                                Stats
                                            </p>
                                            <p className="text-xs text-gray-700">
                                                {parsedSelectedSkill.wordCount} words • {parsedSelectedSkill.sections.length} sections
                                            </p>
                                            <p className="mt-1 text-xs text-gray-600">
                                                {parsedSelectedSkill.preview || 'No preview text available.'}
                                            </p>
                                        </div>
                                    </div>
                                )}

                                <textarea
                                    value={editorContent}
                                    onChange={(event) => {
                                        setEditorContent(event.target.value);
                                        setSaveMessage(null);
                                    }}
                                    spellCheck={false}
                                    className="min-h-0 flex-1 w-full rounded-md border border-gray-200 bg-gray-50 p-3 font-mono text-sm leading-6 text-gray-800 focus:border-gray-400 focus:bg-white focus:outline-none"
                                />

                                <div className="mt-2 flex items-center justify-between text-xs text-gray-500">
                                    <span>Last updated: {new Date(selectedSkill.updated_at).toLocaleString()}</span>
                                    {saveMessage && <span>{saveMessage}</span>}
                                </div>
                                {errors.key && (
                                    <p className="mt-2 text-xs text-red-600">{errors.key}</p>
                                )}
                                {errors.content && (
                                    <p className="mt-1 text-xs text-red-600">{errors.content}</p>
                                )}
                            </div>
                        )}
                    </section>
                </div>
            </div>
        </AppLayout>
    );
}
