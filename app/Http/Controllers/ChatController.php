<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChatMessageRequest;
use App\Services\ChatMemoryService;
use App\Services\OllamaSkillLoader;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ChatController extends Controller
{
    public function __construct(
        private readonly OllamaSkillLoader $skillLoader,
        private readonly ChatMemoryService $chatMemory
    ) {}

    /**
     * Send a chat completion request to Ollama.
     */
    public function store(ChatMessageRequest $request): JsonResponse
    {
        $traceId = (string) Str::uuid();
        $model = (string) config('services.ollama.model');
        $conversation = $this->chatMemory->resolveConversation(
            $request->user(),
            $request->validated('conversation_id')
        );
        $latestUserMessage = $this->latestUserMessage($request->validated('messages'));

        if ($latestUserMessage === '') {
            return response()->json([
                'message' => 'A user message is required.',
            ], 422);
        }

        $messages = [
            ...$this->chatMemory->recentMessages($conversation),
            ['role' => 'user', 'content' => $latestUserMessage],
        ];

        Log::info('Chat store request started', [
            'trace_id' => $traceId,
            'user_id' => $request->user()?->id,
            'model' => $model,
            'message_count' => count($messages),
        ]);

        if ($model === '') {
            Log::warning('Chat store missing model configuration', [
                'trace_id' => $traceId,
            ]);

            return response()->json([
                'message' => 'OLLAMA_MODEL is not configured.',
                'conversation_id' => $conversation->id,
            ], 500)->header('X-Conversation-Id', $conversation->id);
        }

        $messages = $this->prependSystemMessage($messages);

        try {
            $response = Http::baseUrl((string) config('services.ollama.base_url'))
                ->timeout((int) config('services.ollama.timeout', 120))
                ->acceptJson()
                ->post('/api/chat', [
                    'model' => $model,
                    'messages' => $messages,
                    'stream' => false,
                ]);
        } catch (ConnectionException) {
            Log::error('Chat store connection to Ollama failed', [
                'trace_id' => $traceId,
                'base_url' => (string) config('services.ollama.base_url'),
            ]);

            return response()->json([
                'message' => 'Could not connect to Ollama.',
                'details' => 'Check OLLAMA_BASE_URL and confirm the Ollama server is running.',
                'base_url' => (string) config('services.ollama.base_url'),
                'conversation_id' => $conversation->id,
            ], 503)->header('X-Conversation-Id', $conversation->id);
        } catch (Throwable $exception) {
            Log::error('Chat store unexpected Ollama error', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unexpected error while calling Ollama.',
                'details' => $exception->getMessage(),
                'conversation_id' => $conversation->id,
            ], 500)->header('X-Conversation-Id', $conversation->id);
        }

        if (! $response->successful()) {
            $details = $response->json('error') ?? $response->json() ?? $response->body();

            if (is_array($details)) {
                $details = json_encode($details);
            }

            $details = is_string($details) && $details !== ''
                ? $details
                : 'No details returned by Ollama.';

            $message = 'Ollama request failed.';

            if (str_contains(strtolower($details), 'model') && str_contains(strtolower($details), 'not found')) {
                $message = "Model '{$model}' was not found in Ollama.";
            }

            Log::warning('Chat store Ollama returned non-success status', [
                'trace_id' => $traceId,
                'upstream_status' => $response->status(),
                'message' => $message,
            ]);

            return response()->json([
                'message' => $message,
                'details' => $details,
                'upstream_status' => $response->status(),
                'model' => $model,
                'conversation_id' => $conversation->id,
            ], 502)->header('X-Conversation-Id', $conversation->id);
        }

        $responseJson = $response->json();
        if (! is_array($responseJson)) {
            $responseJson = [];
        }

        $content = data_get($responseJson, 'message.content');
        $contextUsage = $this->buildContextUsageFromResponse($responseJson);

        if (! is_string($content) || $content === '') {
            Log::warning('Chat store unexpected Ollama payload', [
                'trace_id' => $traceId,
                'upstream_status' => $response->status(),
            ]);

            return response()->json([
                'message' => 'Ollama returned an unexpected response format.',
                'conversation_id' => $conversation->id,
            ], 502)->header('X-Conversation-Id', $conversation->id);
        }

        $this->chatMemory->persistExchange(
            $conversation,
            $latestUserMessage,
            $content,
            [
                'trace_id' => $traceId,
                'mode' => 'store',
                'context_usage' => $contextUsage,
            ]
        );

        Log::info('Chat store request completed', [
            'trace_id' => $traceId,
            'upstream_status' => $response->status(),
            'response_chars' => strlen($content),
            'context_usage' => $contextUsage,
        ]);

        return response()->json([
            'message' => $content,
            'conversation_id' => $conversation->id,
            'context_usage' => $contextUsage,
        ])->header('X-Conversation-Id', $conversation->id);
    }

    /**
     * Stream a chat completion request from Ollama as NDJSON events.
     */
    public function stream(ChatMessageRequest $request): StreamedResponse
    {
        $traceId = (string) Str::uuid();
        $model = (string) config('services.ollama.model');
        $conversation = $this->chatMemory->resolveConversation(
            $request->user(),
            $request->validated('conversation_id')
        );
        $latestUserMessage = $this->latestUserMessage($request->validated('messages'));

        if ($latestUserMessage === '') {
            return response()->stream(function (): void {
                echo json_encode(['type' => 'error', 'message' => 'A user message is required.'])."\n";
            }, 422, [
                ...$this->streamHeaders(),
                'X-Conversation-Id' => $conversation->id,
            ]);
        }

        $messages = [
            ...$this->chatMemory->recentMessages($conversation),
            ['role' => 'user', 'content' => $latestUserMessage],
        ];

        Log::info('Chat stream request started', [
            'trace_id' => $traceId,
            'user_id' => $request->user()?->id,
            'model' => $model,
            'message_count' => count($messages),
        ]);

        if ($model === '') {
            Log::warning('Chat stream missing model configuration', [
                'trace_id' => $traceId,
            ]);

            return response()->stream(function (): void {
                echo json_encode(['type' => 'error', 'message' => 'OLLAMA_MODEL is not configured.'])."\n";
            }, 500, [
                ...$this->streamHeaders(),
                'X-Conversation-Id' => $conversation->id,
            ]);
        }

        $messages = $this->prependSystemMessage($messages);

        return response()->stream(function () use ($model, $messages, $traceId, $conversation, $latestUserMessage): void {
            $runner = new \App\Services\OllamaToolRunner(
                $model,
                $traceId,
                (int) $conversation->user_id,
            );
            $assistantMessage = '';
            $completed = false;
            $latestContextUsage = null;

            $runner->runWithStreaming($messages, function (string $type, array $data) use (&$assistantMessage, &$completed, &$latestContextUsage): void {
                if ($type === 'delta' && is_string($data['content'] ?? null)) {
                    $assistantMessage .= $data['content'];
                }
                if ($type === 'done') {
                    $completed = true;
                }
                if ($type === 'context_usage') {
                    $latestContextUsage = $data;
                }

                echo json_encode(['type' => $type, ...$data])."\n";
                @ob_flush();
                flush();
            });

            if ($completed) {
                $this->chatMemory->persistExchange(
                    $conversation,
                    $latestUserMessage,
                    $assistantMessage,
                    [
                        'trace_id' => $traceId,
                        'mode' => 'stream',
                        'context_usage' => $latestContextUsage,
                    ]
                );
            }
        }, 200, [
            ...$this->streamHeaders(),
            'X-Conversation-Id' => $conversation->id,
        ]);
    }

    private function streamHeaders(): array
    {
        return [
            'Content-Type'     => 'application/x-ndjson',
            'Cache-Control'    => 'no-cache, no-transform',
            'Connection'       => 'keep-alive',
            'X-Accel-Buffering'=> 'no',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    private function prependSystemMessage(array $messages): array
    {
        $basePrompt = (string) config('services.ollama.system_prompt', '');
        $skillsSection = (string) config('services.ollama.skills_section', '');
        $selectedSkill = $this->skillLoader->selectForMessages($messages);
        $latestUserText = $this->latestUserMessage($messages);
        $appTimezone = (string) config('app.timezone', 'UTC');
        $nowIso = now($appTimezone)->toIso8601String();
        $activeSkill = null;
        if ($selectedSkill !== null) {
            $activeSkill = "## Active Skill: {$selectedSkill['name']}\n".
                "Description: {$selectedSkill['description']}\n\n".
                $selectedSkill['content'];
        }
        $taskIntentDirective = null;
        if ($this->hasTaskIntent($latestUserText)) {
            $taskIntentDirective = <<<TXT
## Active Task Intent
The latest user message indicates scheduling/background intent.
Current server datetime is {$nowIso} ({$appTimezone}).
You must create a `create_task` tool call with a complete payload for this request unless required fields are genuinely missing.
If required fields are missing, ask one concise clarification question.
For `schedule_type=one_time`, `run_at` must be a future datetime in the requested timezone.
When the user gives a relative time (today, tonight, at 12:10am) resolve it against the current server datetime above.
If the implied time today has already passed, choose the next valid future occurrence (typically tomorrow) and state that assumption.
Do not ask the user for the current date/time because it is already provided above.
Never fabricate task IDs, counts, task history tables, or placeholder links.
Only mention links and task IDs returned by successful tool results.
TXT;
        }

        $parts = array_filter([
            trim($basePrompt),
            trim($skillsSection),
            trim((string) $activeSkill),
            trim((string) $taskIntentDirective),
        ]);

        if ($parts === []) {
            return $messages;
        }

        $combined = implode("\n\n", $parts);

        return [
            ['role' => 'system', 'content' => $combined],
            ...$messages,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     */
    private function latestUserMessage(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $message = $messages[$i];
            $role = (string) ($message['role'] ?? '');
            $content = trim((string) ($message['content'] ?? ''));

            if ($role === 'user' && $content !== '') {
                return $content;
            }
        }

        return '';
    }

    private function hasTaskIntent(string $text): bool
    {
        if (trim($text) === '') {
            return false;
        }

        return preg_match(
            '/\b(task|background|later|schedule|scheduled|every|daily|weekly|monthly|cron|monitor|watch|alert me|remind me|automatically|in \d+\s*(minute|minutes|hour|hours|day|days)|tomorrow|next week|end of day|at \d{1,2}:\d{2})\b/i',
            $text
        ) === 1;
    }

    /**
     * @param array<string, mixed> $responseJson
     * @return array<string, int|float>
     */
    private function buildContextUsageFromResponse(array $responseJson): array
    {
        $promptEvalCount = max(0, (int) ($responseJson['prompt_eval_count'] ?? 0));
        $evalCount = max(0, (int) ($responseJson['eval_count'] ?? 0));
        $contextWindow = max(1, (int) config('services.ollama.context_window', 234000));
        $usedPct = round(($promptEvalCount / $contextWindow) * 100, 2);

        return [
            'prompt_eval_count' => $promptEvalCount,
            'eval_count' => $evalCount,
            'context_window' => $contextWindow,
            'context_used_pct' => $usedPct,
            'context_remaining' => max(0, $contextWindow - $promptEvalCount),
        ];
    }
}
