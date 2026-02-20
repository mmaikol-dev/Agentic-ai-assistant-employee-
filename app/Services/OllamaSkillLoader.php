<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class OllamaSkillLoader
{
    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array{name: string, description: string, content: string, path: string}|null
     */
    public function selectForMessages(array $messages): ?array
    {
        $latestUserText = $this->latestUserMessage($messages);
        if ($latestUserText === '') {
            return null;
        }

        $skills = $this->loadSkills();
        if ($skills === []) {
            return null;
        }

        $query = Str::lower($latestUserText);
        $bestSkill = null;
        $bestScore = 0;

        foreach ($skills as $skill) {
            $score = $this->scoreSkill($skill, $query);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestSkill = $skill;
            }
        }

        return $bestScore > 0 ? $bestSkill : null;
    }

    /**
     * @return array<int, array{name: string, description: string, triggers: array<int, string>, content: string, path: string}>
     */
    private function loadSkills(): array
    {
        $skillsPath = (string) config('services.ollama.skills_path', resource_path('ai/skills'));
        if ($skillsPath === '' || ! File::isDirectory($skillsPath)) {
            return [];
        }

        $skills = [];
        foreach (File::allFiles($skillsPath) as $file) {
            if ($file->getFilename() !== 'SKILL.md') {
                continue;
            }

            $raw = File::get($file->getPathname());
            [$meta, $content] = $this->parseSkill($raw);

            $name = trim((string) ($meta['name'] ?? ''));
            $description = trim((string) ($meta['description'] ?? ''));
            if ($name === '' || $description === '' || trim($content) === '') {
                continue;
            }

            $triggers = array_values(array_filter(array_map(
                fn ($v) => trim(Str::lower((string) $v)),
                explode(',', (string) ($meta['triggers'] ?? ''))
            )));

            $skills[] = [
                'name' => $name,
                'description' => $description,
                'triggers' => $triggers,
                'content' => trim($content),
                'path' => $file->getPathname(),
            ];
        }

        return $skills;
    }

    /**
     * @param array{name: string, description: string, triggers: array<int, string>, content: string, path: string} $skill
     */
    private function scoreSkill(array $skill, string $query): int
    {
        $score = 0;
        $name = Str::lower($skill['name']);

        if (str_contains($query, '$'.$name) || str_contains($query, $name)) {
            $score += 100;
        }

        foreach ($skill['triggers'] as $trigger) {
            if ($trigger !== '' && str_contains($query, $trigger)) {
                $score += 15;
            }
        }

        $descriptionTokens = preg_split('/[^a-z0-9]+/i', Str::lower($skill['description'])) ?: [];
        foreach ($descriptionTokens as $token) {
            if (strlen($token) < 4) {
                continue;
            }
            if (in_array($token, ['with', 'that', 'from', 'this', 'using', 'tool', 'tools'], true)) {
                continue;
            }
            if (str_contains($query, $token)) {
                $score += 2;
            }
        }

        return $score;
    }

    /**
     * @return array{0: array<string, string>, 1: string}
     */
    private function parseSkill(string $raw): array
    {
        if (! preg_match('/^---\R(.*?)\R---\R(.*)$/s', $raw, $matches)) {
            return [[], $raw];
        }

        $frontMatter = trim($matches[1]);
        $body = $matches[2];
        $meta = [];

        foreach (preg_split('/\R/', $frontMatter) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode(':', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $value = trim($parts[1]);
            $value = trim($value, " \t\n\r\0\x0B\"'");

            if ($key !== '') {
                $meta[$key] = $value;
            }
        }

        return [$meta, $body];
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     */
    private function latestUserMessage(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $message = $messages[$i];
            $role = (string) ($message['role'] ?? '');
            $content = (string) ($message['content'] ?? '');

            if ($role === 'user' && trim($content) !== '') {
                return $content;
            }
        }

        return '';
    }
}

