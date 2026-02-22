<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AgentPlannerService
{
    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $tools
     * @return array<string, mixed>
     */
    public function buildPlan(array $messages, array $tools, string $model, string $baseUrl, int $timeout): array
    {
        $goal = $this->latestUserMessage($messages);
        if ($goal === '') {
            return $this->fallbackPlan('No explicit user goal detected.');
        }

        $plannerEnabled = (bool) config('services.ollama.enable_planner', true);
        if (! $plannerEnabled) {
            return $this->fallbackPlan($goal);
        }

        $toolNames = collect($tools)
            ->map(fn (array $tool) => (string) data_get($tool, 'function.name', ''))
            ->filter(fn (string $name) => $name !== '')
            ->values()
            ->all();

        $plannerMessages = [
            [
                'role' => 'system',
                'content' => "You are a planning module. Return strict JSON only with keys: goal, success_criteria, steps.\n"
                    ."Each step must include: step, action, tool, depends_on, risk.\n"
                    ."Valid risk values: low, medium, high, critical.\n"
                    ."Use available tools only: ".implode(', ', $toolNames),
            ],
            ['role' => 'user', 'content' => $goal],
        ];

        try {
            $plannerTimeout = max(3, min(20, (int) config('services.ollama.planner_timeout', min($timeout, 12))));
            $response = Http::baseUrl($baseUrl)
                ->timeout($plannerTimeout)
                ->acceptJson()
                ->post('/api/chat', [
                    'model' => $model,
                    'messages' => $plannerMessages,
                    'stream' => false,
                ]);
        } catch (\Throwable $e) {
            Log::warning('Planner call failed, using fallback plan', ['error' => $e->getMessage()]);
            return $this->fallbackPlan($goal);
        }

        if (! $response->successful()) {
            return $this->fallbackPlan($goal);
        }

        $content = (string) data_get($response->json(), 'message.content', '');
        if ($content === '') {
            return $this->fallbackPlan($goal);
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            return $this->fallbackPlan($goal);
        }

        if (! isset($decoded['goal']) || ! is_string($decoded['goal'])) {
            $decoded['goal'] = $goal;
        }

        if (! isset($decoded['steps']) || ! is_array($decoded['steps']) || $decoded['steps'] === []) {
            return $this->fallbackPlan($goal);
        }

        if (! isset($decoded['success_criteria'])) {
            $decoded['success_criteria'] = ['Task completed with validated tool outputs.'];
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function fallbackPlan(string $goal): array
    {
        return [
            'goal' => $goal,
            'success_criteria' => [
                'Execute requested actions with successful tool responses.',
                'Return clear failure reasons when retries fail.',
            ],
            'steps' => [
                [
                    'step' => 1,
                    'action' => 'Analyze request and choose tools.',
                    'tool' => 'none',
                    'depends_on' => [],
                    'risk' => 'medium',
                ],
                [
                    'step' => 2,
                    'action' => 'Execute selected tools with retry/recovery.',
                    'tool' => 'dynamic',
                    'depends_on' => [1],
                    'risk' => 'medium',
                ],
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     */
    private function latestUserMessage(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $role = (string) ($messages[$i]['role'] ?? '');
            $content = trim((string) ($messages[$i]['content'] ?? ''));
            if ($role === 'user' && $content !== '') {
                return $content;
            }
        }

        return '';
    }
}
