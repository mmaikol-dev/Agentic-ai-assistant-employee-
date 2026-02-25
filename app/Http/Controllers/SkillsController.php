<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SkillsController extends Controller
{
    public function index(): Response
    {
        $root = resource_path('ai/skills');
        if (! File::isDirectory($root)) {
            return Inertia::render('skills/index', [
                'skills' => [],
            ]);
        }

        $skills = collect(File::allFiles($root))
            ->filter(fn ($file): bool => $file->getFilename() === 'SKILL.md')
            ->map(function ($file) use ($root): array {
                $fullPath = $file->getPathname();
                $relativePath = Str::of($fullPath)
                    ->replace('\\', '/')
                    ->after(str_replace('\\', '/', $root).'/')
                    ->toString();
                $skillKey = Str::beforeLast($relativePath, '/SKILL.md');
                $label = (string) Str::of($skillKey)->afterLast('/')->replace('-', ' ')->title();

                return [
                    'key' => $skillKey,
                    'label' => $label,
                    'path' => $relativePath,
                    'content' => (string) File::get($fullPath),
                    'updated_at' => now()->setTimestamp($file->getMTime())->toIso8601String(),
                ];
            })
            ->sortBy('label')
            ->values()
            ->all();

        return Inertia::render('skills/index', [
            'skills' => $skills,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string'],
            'content' => ['present', 'string'],
        ]);

        $path = $this->resolveSkillFilePath((string) $validated['key']);
        if ($path === null) {
            return back()->withErrors([
                'key' => 'Invalid skill path.',
            ]);
        }

        File::put($path, (string) $validated['content']);

        return to_route('skills.index', status: 303);
    }

    private function resolveSkillFilePath(string $key): ?string
    {
        $root = resource_path('ai/skills');
        $realRoot = realpath($root);
        if ($realRoot === false) {
            return null;
        }

        $normalized = trim(str_replace('\\', '/', $key), '/');
        if ($normalized === '' || str_contains($normalized, '..')) {
            return null;
        }

        $candidateDir = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $normalized);
        $realCandidateDir = realpath($candidateDir);
        if ($realCandidateDir === false || ! str_starts_with($realCandidateDir, $realRoot)) {
            return null;
        }

        $candidateFile = $realCandidateDir.DIRECTORY_SEPARATOR.'SKILL.md';
        if (! File::exists($candidateFile)) {
            return null;
        }

        return $candidateFile;
    }
}
