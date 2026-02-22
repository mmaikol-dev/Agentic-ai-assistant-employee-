<?php

namespace App\Services;

use App\Mcp\Tools\ModelSchemaWorkspaceTool;
use App\Models\SheetOrder;
use App\Services\ReportTaskService;
use App\Services\Whatsapp\WhatsappMessageSender;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;

class OllamaToolRunner
{
    private string $model;
    private string $baseUrl;
    private int $timeout;
    private ?string $traceId;
    private ?int $userId;
    private AgentToolPolicyService $policy;
    private AgentPlannerService $planner;
    private ToolExecutionOrchestrator $orchestrator;
    private ToolCriticService $critic;
    private AgentMemoryService $memory;
    private McpToolInvokerService $mcpInvoker;
    private bool $dynamicToolsEnabled = false;
    private ?string $requestDomain = null;

    /**
     * @var array<string, class-string>
     */
    private array $mcpToolMap = [];

    private array $tools = [
        [
            'type' => 'function',
            'function' => [
                'name'        => 'list_orders',
                'description' => 'List orders from the sheet_orders table. Supports filtering by status, client_name, merchant, phone, code, alt_no, agent, city, country, search query, and pagination.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'page'        => ['type' => 'integer', 'description' => 'Page number (default 1)'],
                        'per_page'    => ['type' => 'integer', 'description' => 'Results per page (default 15, max 50)'],
                        'status'      => ['type' => 'string',  'description' => 'Filter by order status'],
                        'client_name' => ['type' => 'string',  'description' => 'Filter by client name (partial match)'],
                        'merchant'    => ['type' => 'string',  'description' => 'Filter by merchant (partial match)'],
                        'phone'       => ['type' => 'string',  'description' => 'Filter by phone (partial match)'],
                        'code'        => ['type' => 'string',  'description' => 'Filter by code (partial match)'],
                        'code_is_empty' => ['type' => 'boolean', 'description' => 'Set true to return rows where code is null/empty; false for non-empty code'],
                        'alt_no'      => ['type' => 'string',  'description' => 'Filter by alternative number (partial match)'],
                        'agent'       => ['type' => 'string',  'description' => 'Filter by agent name'],
                        'city'        => ['type' => 'string',  'description' => 'Filter by city'],
                        'country'     => ['type' => 'string',  'description' => 'Filter by country'],
                        'search'      => ['type' => 'string',  'description' => 'Search across order_no, product_name, client_name, merchant, city, agent, phone, code, alt_no, store_name'],
                    ],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name'        => 'get_order',
                'description' => 'Get full details of a single order by its id or order_no.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'id'       => ['type' => 'integer', 'description' => 'Order primary key'],
                        'order_no' => ['type' => 'string',  'description' => 'Order number'],
                    ],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name'        => 'create_order',
                'description' => 'Create a new order in the sheet_orders table.',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['order_no', 'client_name', 'product_name', 'amount'],
                    'properties' => [
                        'order_no'      => ['type' => 'string'],
                        'order_date'    => ['type' => 'string', 'description' => 'ISO date e.g. 2025-01-15'],
                        'amount'        => ['type' => 'number'],
                        'quantity'      => ['type' => 'integer'],
                        'item'          => ['type' => 'string'],
                        'delivery_date' => ['type' => 'string'],
                        'client_name'   => ['type' => 'string'],
                        'client_city'   => ['type' => 'string'],
                        'address'       => ['type' => 'string'],
                        'product_name'  => ['type' => 'string'],
                        'city'          => ['type' => 'string'],
                        'country'       => ['type' => 'string'],
                        'phone'         => ['type' => 'string'],
                        'status'        => ['type' => 'string'],
                        'agent'         => ['type' => 'string'],
                        'store_name'    => ['type' => 'string'],
                        'confirmed'     => ['type' => 'boolean', 'description' => 'Explicit confirmation for high-risk action'],
                        'comments'      => ['type' => 'string'],
                        'instructions'  => ['type' => 'string'],
                    ],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name'        => 'edit_order',
                'description' => 'Edit/update an existing order. Provide id OR order_no to identify it, then only the fields you want to change.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'id'            => ['type' => 'integer', 'description' => 'Order primary key'],
                        'order_no'      => ['type' => 'string',  'description' => 'Order number'],
                        'amount'        => ['type' => 'number'],
                        'quantity'      => ['type' => 'integer'],
                        'status'        => ['type' => 'string'],
                        'delivery_date' => ['type' => 'string'],
                        'client_name'   => ['type' => 'string'],
                        'client_city'   => ['type' => 'string'],
                        'address'       => ['type' => 'string'],
                        'product_name'  => ['type' => 'string'],
                        'city'          => ['type' => 'string'],
                        'country'       => ['type' => 'string'],
                        'phone'         => ['type' => 'string'],
                        'agent'         => ['type' => 'string'],
                        'store_name'    => ['type' => 'string'],
                        'confirmed'     => ['type' => 'boolean'],
                        'comments'      => ['type' => 'string'],
                        'instructions'  => ['type' => 'string'],
                    ],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name'        => 'scaffold_mcp_tool',
                'description' => 'Create a new MCP tool class inside app/Mcp/Tools, with schema and validation boilerplate, and optionally register it in OrdersServer.',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['tool_name', 'description'],
                    'properties' => [
                        'tool_name' => ['type' => 'string', 'description' => 'Tool class name, suffix Tool optional'],
                        'description' => ['type' => 'string', 'description' => 'Description for the generated tool'],
                        'tool_kind' => ['type' => 'string', 'description' => 'Tool kind: basic, query, mutation, report, custom (auto-inferred if omitted). Unknown values fall back to basic.'],
                        'arguments' => ['type' => 'array', 'description' => 'Argument definitions: [{name,type,description,required,nullable}]'],
                        'task_notes' => ['type' => 'string', 'description' => 'Optional implementation notes inserted into the generated handle()'],
                        'register_in_orders_server' => ['type' => 'boolean', 'description' => 'Register tool in OrdersServer (default true)'],
                        'overwrite' => ['type' => 'boolean', 'description' => 'Overwrite tool file if it exists (default false)'],
                    ],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name'        => 'model_schema_workspace',
                'description' => 'Inspect app/Models and database table schemas, then scaffold table-specific MCP tools (list/get/create/update).',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['action'],
                    'properties' => [
                        'action' => ['type' => 'string', 'enum' => ['list_models', 'describe_model', 'scaffold_tools']],
                        'model' => ['type' => 'string', 'description' => 'Model name or class, e.g. User or App\\Models\\User'],
                        'table' => ['type' => 'string', 'description' => 'Table name override, e.g. users'],
                        'operations' => ['type' => 'array', 'description' => 'For scaffold_tools: operations list from list,get,create,update'],
                        'register_in_orders_server' => ['type' => 'boolean', 'description' => 'Register generated tools in OrdersServer (default true)'],
                        'overwrite' => ['type' => 'boolean', 'description' => 'Overwrite generated files when true'],
                    ],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name'        => 'financial_report',
                'description' => 'Generate a financial report for delivered + remitted orders with optional merchant/date/location/agent filters, including totals, product and city breakdowns.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'merchant' => ['type' => 'string', 'description' => 'Merchant name (partial match)'],
                        'start_date' => ['type' => 'string', 'description' => 'Start date (YYYY-MM-DD)'],
                        'end_date' => ['type' => 'string', 'description' => 'End date (YYYY-MM-DD)'],
                        'country' => ['type' => 'string', 'description' => 'Country filter'],
                        'city' => ['type' => 'string', 'description' => 'City filter (partial match)'],
                        'agent' => ['type' => 'string', 'description' => 'Agent filter. If omitted, defaults to remitted variants.'],
                        'limit' => ['type' => 'integer', 'description' => 'Number of orders to include in listing section, max 200, default 50'],
                    ],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name'        => 'create_report_task',
                'description' => 'Create a multi-merchant report workflow task. Steps: check scheduled+coded orders, confirm delivery update, confirm remitted update, then provide report download links.',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['merchants'],
                    'properties' => [
                        'merchants' => [
                            'type' => 'array',
                            'description' => 'List of merchant workflows: [{merchant,start_date,end_date}] with dates in YYYY-MM-DD.',
                        ],
                    ],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name'        => 'get_report_task_status',
                'description' => 'Get current status of a report workflow task by task_id.',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['task_id'],
                    'properties' => [
                        'task_id' => ['type' => 'string', 'description' => 'Task id UUID'],
                    ],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name'        => 'setup_integration',
                'description' => 'Set up an external integration scaffold (e.g. WhatsApp). If provider is missing, returns provider choices and required inputs.',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['integration_name'],
                    'properties' => [
                        'integration_name' => ['type' => 'string', 'description' => 'Integration name, e.g. whatsapp'],
                        'provider' => ['type' => 'string', 'description' => 'Provider choice, e.g. meta, twilio, africastalking, custom'],
                        'documentation_url' => ['type' => 'string', 'description' => 'Optional docs URL (required for custom provider)'],
                        'overwrite' => ['type' => 'boolean', 'description' => 'Overwrite generated files if they exist'],
                    ],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name'        => 'send_whatsapp_message',
                'description' => 'Send a WhatsApp message using the configured WhatsApp provider integration.',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['to', 'message'],
                    'properties' => [
                        'to' => ['type' => 'string', 'description' => 'Recipient phone number in international format, e.g. +2547...'],
                        'message' => ['type' => 'string', 'description' => 'Message body to send'],
                        'confirmed' => ['type' => 'boolean', 'description' => 'Explicit confirmation for high-risk action'],
                    ],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'create_task',
                'description' => 'Create a background task that runs immediately, at a future time, on a recurring cron schedule, or when an event condition becomes true.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string', 'description' => 'Short descriptive title for the task'],
                        'description' => ['type' => 'string', 'description' => 'What this task will do'],
                        'schedule_type' => ['type' => 'string', 'enum' => ['immediate', 'one_time', 'recurring', 'event_triggered']],
                        'run_at' => ['type' => 'string', 'description' => 'ISO8601 datetime for one_time tasks'],
                        'cron_expression' => ['type' => 'string', 'description' => 'Cron expression for recurring tasks'],
                        'cron_human' => ['type' => 'string', 'description' => 'Human readable schedule description'],
                        'event_condition' => ['type' => 'string', 'description' => 'Condition for event-triggered tasks'],
                        'timezone' => ['type' => 'string', 'description' => 'IANA timezone e.g. Africa/Nairobi'],
                        'priority' => ['type' => 'string', 'enum' => ['low', 'normal', 'high']],
                        'execution_plan' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'step' => ['type' => 'integer'],
                                    'action' => ['type' => 'string'],
                                    'tool' => ['type' => 'string'],
                                    'tool_input' => ['type' => 'object'],
                                    'input_summary' => ['type' => 'string'],
                                    'depends_on' => ['type' => 'array', 'items' => ['type' => 'integer']],
                                ],
                            ],
                        ],
                        'expected_output' => ['type' => 'string'],
                        'original_user_request' => ['type' => 'string'],
                        'confirmed' => ['type' => 'boolean', 'description' => 'Optional explicit confirmation to propagate to high-risk steps in execution_plan'],
                    ],
                    'required' => ['title', 'schedule_type'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name'        => 'send_email',
                'description' => 'Send an email via SendGrid.',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['to', 'subject', 'content'],
                    'properties' => [
                        'to' => ['type' => 'string', 'description' => 'Recipient email address'],
                        'subject' => ['type' => 'string', 'description' => 'Email subject'],
                        'content' => ['type' => 'string', 'description' => 'Email body content'],
                        'content_type' => ['type' => 'string', 'description' => 'text/plain or text/html'],
                        'from_email' => ['type' => 'string', 'description' => 'Optional sender email'],
                        'from_name' => ['type' => 'string', 'description' => 'Optional sender name'],
                        'confirmed' => ['type' => 'boolean', 'description' => 'Explicit confirmation for high-risk action'],
                    ],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name'        => 'send_grid_email',
                'description' => 'Send an email via SendGrid (alias of send_email).',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['to', 'subject', 'content'],
                    'properties' => [
                        'to' => ['type' => 'string', 'description' => 'Recipient email address'],
                        'subject' => ['type' => 'string', 'description' => 'Email subject'],
                        'content' => ['type' => 'string', 'description' => 'Email body content'],
                        'content_type' => ['type' => 'string', 'description' => 'text/plain or text/html'],
                        'from_email' => ['type' => 'string', 'description' => 'Optional sender email'],
                        'from_name' => ['type' => 'string', 'description' => 'Optional sender name'],
                        'confirmed' => ['type' => 'boolean', 'description' => 'Explicit confirmation for high-risk action'],
                    ],
                ],
            ],
        ],
    ];

    public function __construct(string $model, ?string $traceId = null, ?int $userId = null)
    {
        $this->model   = $model;
        $this->baseUrl = (string) config('services.ollama.base_url');
        $this->timeout = (int) config('services.ollama.timeout', 120);
        $this->traceId = $traceId;
        $this->userId = $userId;
        $this->policy = app(AgentToolPolicyService::class);
        $this->planner = app(AgentPlannerService::class);
        $this->orchestrator = app(ToolExecutionOrchestrator::class);
        $this->critic = app(ToolCriticService::class);
        $this->memory = app(AgentMemoryService::class);
        $this->mcpInvoker = app(McpToolInvokerService::class);

        $this->dynamicToolsEnabled = (bool) config('services.ollama.enable_dynamic_tools', true);
        if ($this->dynamicToolsEnabled) {
            $this->refreshDynamicToolRegistry();
        }
    }

    /**
     * Run the agentic loop: send messages to Ollama, handle tool calls,
     * feed results back, repeat until a final text response is produced.
     *
     * @param array    $messages  The conversation so far
     * @param callable $emit      fn(string $type, array $data) — streams events to the client
     */
    public function runWithStreaming(array $messages, callable $emit): void
    {
        $maxIterations = 8; // safety cap against infinite loops
        $toolCallsCount = 0;
        $confirmedTaskCreated = false;
        $latestUserPrompt = $this->latestUserPrompt($messages);
        $this->requestDomain = $this->inferRequestDomain($latestUserPrompt);
        $executedToolNames = [];
        $successfulToolResults = [];
        $toolOutcomeSummary = [];
        $emit('status', ['phase' => 'planning']);
        $plan = $this->planner->buildPlan($messages, $this->tools, $this->model, $this->baseUrl, $this->timeout);

        $emit('plan', ['plan' => $plan]);
        $messages[] = [
            'role' => 'system',
            'content' => $this->renderPlanDirective($plan),
        ];

        Log::info('Ollama tool runner started', [
            'trace_id' => $this->traceId,
            'model' => $this->model,
            'message_count' => count($messages),
            'max_iterations' => $maxIterations,
        ]);

        for ($i = 0; $i < $maxIterations; $i++) {
            Log::debug('Ollama tool runner iteration started', [
                'trace_id' => $this->traceId,
                'iteration' => $i + 1,
                'message_count' => count($messages),
            ]);

            try {
                $response = Http::baseUrl($this->baseUrl)
                    ->timeout($this->timeout)
                    ->acceptJson()
                    ->post('/api/chat', [
                        'model'    => $this->model,
                        'messages' => $messages,
                        'tools'    => $this->tools,
                        'stream'   => false,
                    ]);
            } catch (\Illuminate\Http\Client\ConnectionException) {
                Log::error('Ollama tool runner connection failed', [
                    'trace_id' => $this->traceId,
                    'base_url' => $this->baseUrl,
                ]);

                $emit('error', [
                    'message' => 'Could not connect to Ollama.',
                    'details' => 'Check OLLAMA_BASE_URL and confirm the Ollama server is running.',
                ]);
                $emit('done', []);
                return;
            } catch (\Throwable $e) {
                Log::error('Ollama tool runner unexpected request error', [
                    'trace_id' => $this->traceId,
                    'error' => $e->getMessage(),
                ]);

                $emit('error', [
                    'message' => 'Unexpected error calling Ollama.',
                    'details' => $e->getMessage(),
                ]);
                $emit('done', []);
                return;
            }

            if (! $response->successful()) {
                Log::warning('Ollama tool runner non-success response', [
                    'trace_id' => $this->traceId,
                    'upstream_status' => $response->status(),
                    'upstream_body' => $response->body(),
                ]);

                $details = (string) $response->body();
                if (
                    $response->status() === 400
                    && str_contains(strtolower($details), 'looks like object')
                    && str_contains(strtolower($details), 'closing')
                ) {
                    $fallbackMessages = collect($messages)
                        ->reject(function (array $message): bool {
                            $content = (string) ($message['content'] ?? '');
                            $role = (string) ($message['role'] ?? '');
                            return str_starts_with($content, 'Execution Plan:') || $role === 'tool';
                        })
                        ->map(function (array $message): array {
                            $content = (string) ($message['content'] ?? '');
                            if (strlen($content) > 3000) {
                                $content = substr($content, 0, 3000).'...';
                            }
                            return [
                                'role' => (string) ($message['role'] ?? 'user'),
                                'content' => $content,
                            ];
                        })
                        ->values()
                        ->all();

                    try {
                        $fallbackResponse = Http::baseUrl($this->baseUrl)
                            ->timeout($this->timeout)
                            ->acceptJson()
                            ->post('/api/chat', [
                                'model' => $this->model,
                                'messages' => $fallbackMessages,
                                'stream' => false,
                            ]);

                        if ($fallbackResponse->successful()) {
                            $fallbackContent = (string) data_get($fallbackResponse->json(), 'message.content', '');
                            $this->emitStreamedText($fallbackContent, $emit);
                            $emit('done', []);
                            return;
                        }
                    } catch (\Throwable) {
                        // Keep original error response below.
                    }
                }

                $emit('error', [
                    'message'         => 'Ollama request failed.',
                    'details'         => $details,
                    'upstream_status' => $response->status(),
                ]);
                $emit('done', []);
                return;
            }

            $responseMessage = $response->json('message') ?? [];
            $content         = $responseMessage['content'] ?? '';
            $toolCalls       = $responseMessage['tool_calls'] ?? [];
            $contextUsage = $this->buildContextUsagePayload($response->json());

            $emit('context_usage', [
                ...$contextUsage,
                'iteration' => $i + 1,
            ]);

            // Add the assistant turn to history (required for multi-turn tool use)
            $assistantEntry = ['role' => 'assistant', 'content' => $content];
            if (! empty($toolCalls)) {
                $assistantEntry['tool_calls'] = $toolCalls;
            }
            $messages[] = $assistantEntry;

            // ── No tool calls → final answer, stream it and finish ──────────
            if (empty($toolCalls)) {
                if ($this->shouldUseProductInventoryFallback($latestUserPrompt, $executedToolNames)) {
                    $fallbackContent = $this->runProductInventoryFallback($emit, $toolOutcomeSummary, $executedToolNames);
                    if ($fallbackContent !== null) {
                        $this->emitStreamedText($fallbackContent, $emit);
                        $emit('done', []);

                        Log::info('Ollama tool runner completed via product fallback', [
                            'trace_id' => $this->traceId,
                            'iterations' => $i + 1,
                            'tool_calls' => $toolCallsCount,
                            'response_chars' => strlen($fallbackContent),
                        ]);

                        return;
                    }
                }

                $content = $this->sanitizeFinalAssistantContent(
                    content: (string) $content,
                    confirmedTaskCreated: $confirmedTaskCreated,
                    toolOutcomeSummary: $toolOutcomeSummary,
                    successfulToolResults: $successfulToolResults,
                );
                $this->emitStreamedText($content, $emit);
                $emit('done', []);

                Log::info('Ollama tool runner completed', [
                    'trace_id' => $this->traceId,
                    'iterations' => $i + 1,
                    'tool_calls' => $toolCallsCount,
                    'response_chars' => strlen((string) $content),
                    'context_usage' => $contextUsage,
                ]);

                return;
            }

            // ── Has tool calls → execute each, emit results, loop again ─────
            foreach ($toolCalls as $toolCall) {
                $toolName = data_get($toolCall, 'function.name', '');
                $toolArgs = data_get($toolCall, 'function.arguments', []);
                $toolCallsCount++;
                $executedToolNames[] = $toolName;

                // Ollama sometimes returns arguments as a JSON string
                if (is_string($toolArgs)) {
                    $toolArgs = json_decode($toolArgs, true) ?? [];
                }
                if (
                    is_array($toolArgs)
                    && ! array_key_exists('confirmed', $toolArgs)
                    && $this->isHighRiskTool($toolName)
                    && $this->hasExplicitConfirmationMessage($messages)
                ) {
                    $toolArgs['confirmed'] = true;
                }

                Log::debug('Ollama tool runner tool call', [
                    'trace_id' => $this->traceId,
                    'tool' => $toolName,
                    'arguments' => $toolArgs,
                ]);

                // Tell frontend which tool is being invoked
                $emit('tool_call', ['tool' => $toolName, 'args' => $toolArgs]);

                $policy = $this->policy->authorize($toolName, $toolArgs);
                if (! $policy['allowed']) {
                    $result = [
                        'type' => 'policy_blocked',
                        'tool' => $toolName,
                        'risk' => $policy['risk'],
                        'message' => $policy['reason'],
                    ];
                } elseif (($domainError = $this->domainMismatchError($toolName, is_array($toolArgs) ? $toolArgs : [])) !== null) {
                    $result = [
                        'type' => 'error',
                        'tool' => $toolName,
                        'message' => $domainError,
                    ];
                } else {
                    $result = $this->orchestrator->execute(
                        $toolName,
                        $toolArgs,
                        fn (string $name, array $args): array => $this->dispatchTool($name, $args),
                    );
                }

                $critique = $this->critic->evaluate($toolName, $result);
                $result['_policy'] = [
                    'risk' => $policy['risk'],
                    'requires_confirmation' => $policy['requires_confirmation'],
                ];
                $result['_critic'] = $critique;
                $toolOutcomeSummary[] = [
                    'tool' => $toolName,
                    'type' => $result['type'] ?? null,
                    'ok' => $critique['ok'],
                    'message' => $result['message'] ?? null,
                ];
                if (! in_array((string) ($result['type'] ?? ''), ['error', 'policy_blocked'], true)) {
                    $successfulToolResults[] = [
                        'tool' => $toolName,
                        'result' => $result,
                    ];
                }
                if ($toolName === 'create_task' && ($result['type'] ?? null) === 'task_created') {
                    $confirmedTaskCreated = true;
                }
                if (
                    $toolName === 'model_schema_workspace'
                    && ($result['type'] ?? null) === 'model_workspace'
                    && (($result['action'] ?? null) === 'scaffold_tools')
                ) {
                    $this->refreshDynamicToolRegistry();
                    $emit('status', ['phase' => 'executing', 'note' => 'Tool registry refreshed']);
                }

                $emit('critic', [
                    'tool' => $toolName,
                    ...$critique,
                ]);

                if ($this->userId !== null) {
                    $summary = json_encode([
                        'tool' => $toolName,
                        'result_type' => $result['type'] ?? null,
                        'critic_ok' => $critique['ok'],
                    ]);
                    $this->memory->storeEpisode(
                        userId: $this->userId,
                        scope: 'tool_outcome',
                        memoryKey: $toolName,
                        content: is_string($summary) ? $summary : $toolName,
                        metadata: [
                            'tool' => $toolName,
                            'result_type' => $result['type'] ?? null,
                            'risk' => $policy['risk'],
                            'critic' => $critique,
                        ],
                    );
                }

                Log::debug('Ollama tool runner tool result', [
                    'trace_id' => $this->traceId,
                    'tool' => $toolName,
                    'result_type' => $result['type'] ?? 'unknown',
                    'result_message' => $result['message'] ?? null,
                    'result_details' => $result['details'] ?? null,
                    'result_upstream_status' => $result['upstream_status'] ?? null,
                ]);

                // Send structured result to frontend for rich rendering
                $emit('tool_result', [
                    'tool' => $toolName,
                    'result' => $result,
                ]);

                // Feed the result back into the conversation
                $messages[] = [
                    'role'    => 'tool',
                    'content' => $this->compactToolResultForModel($result),
                ];
            }

            // Loop back → Ollama now sees tool results and continues
        }

        Log::warning('Ollama tool runner reached max iterations', [
            'trace_id' => $this->traceId,
            'max_iterations' => $maxIterations,
            'tool_calls' => $toolCallsCount,
        ]);

        $emit('error', ['message' => 'Max tool iterations reached without a final response.']);
        $emit('done', []);
    }

    /**
     * @param array<int, array{tool: string, type: mixed, ok: mixed, message?: mixed}> $toolOutcomeSummary
     * @param array<int, array{tool: string, result: array<string, mixed>}> $successfulToolResults
     */
    private function sanitizeFinalAssistantContent(
        string $content,
        bool $confirmedTaskCreated,
        array $toolOutcomeSummary,
        array $successfulToolResults
    ): string
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            if ($toolOutcomeSummary !== []) {
                $failed = collect($toolOutcomeSummary)
                    ->first(fn (array $row): bool => in_array((string) ($row['type'] ?? ''), ['error', 'policy_blocked'], true));
                if (is_array($failed)) {
                    $tool = (string) ($failed['tool'] ?? 'tool');
                    $message = trim((string) ($failed['message'] ?? ''));
                    if ($message !== '') {
                        return "I could not complete the request because {$tool} failed: {$message}";
                    }

                    return "I could not complete the request because {$tool} failed.";
                }

                $summary = $this->summarizeSuccessfulToolResults($successfulToolResults);
                if ($summary !== null) {
                    return $summary;
                }

                return 'I completed processing the request. Please review the tool results above for details.';
            }

            return 'I could not generate a response this time. Please retry your request.';
        }

        $claimsTaskCreated = preg_match('/\b(task (was|is|has been)?\s*created|created successfully|task id|\/tasks\/)\b/i', $trimmed) === 1;
        if ($claimsTaskCreated && ! $confirmedTaskCreated) {
            return "I could not verify task creation from tool results, so I won't confirm it yet. Please ask me to create the task again.";
        }

        $aligned = $this->alignFinalContentWithToolResults($trimmed, $successfulToolResults);
        if ($aligned !== null) {
            return $aligned;
        }

        return $trimmed;
    }

    /**
     * @param array<int, array{tool: string, result: array<string, mixed>}> $successfulToolResults
     */
    private function summarizeSuccessfulToolResults(array $successfulToolResults): ?string
    {
        if ($successfulToolResults === []) {
            return null;
        }

        $summaries = [];

        foreach ($successfulToolResults as $entry) {
            $tool = (string) ($entry['tool'] ?? 'tool');
            $result = is_array($entry['result'] ?? null) ? $entry['result'] : [];
            $type = (string) ($result['type'] ?? '');

            if ($type === 'model_workspace') {
                $action = (string) ($result['action'] ?? '');
                if ($action === 'list_models') {
                    $count = (int) ($result['count'] ?? 0);
                    $summaries[] = "Model workspace listed {$count} model(s).";
                    continue;
                }
                if ($action === 'describe_model') {
                    $model = (string) ($result['model'] ?? 'unknown model');
                    $table = (string) ($result['table'] ?? 'unknown table');
                    $columns = is_array($result['columns'] ?? null) ? count($result['columns']) : 0;
                    $summaries[] = "Model {$model} maps to table {$table} with {$columns} column(s).";
                    continue;
                }
                if ($action === 'scaffold_tools') {
                    $created = is_array($result['created'] ?? null) ? count($result['created']) : 0;
                    $skipped = is_array($result['skipped'] ?? null) ? count($result['skipped']) : 0;
                    $summaries[] = "Tool scaffolding completed (created {$created}, skipped {$skipped}).";
                    continue;
                }
            }

            if ($type === 'orders_table') {
                $total = (int) ($result['total'] ?? 0);
                $page = (int) ($result['current_page'] ?? 1);
                $lastPage = (int) ($result['last_page'] ?? 1);
                $summaries[] = "Found {$total} order(s) (page {$page} of {$lastPage}).";
                continue;
            }

            if ($type === 'list_product_records') {
                $total = (int) ($result['total'] ?? 0);
                $page = (int) ($result['current_page'] ?? 1);
                $lastPage = (int) ($result['last_page'] ?? 1);
                $summaries[] = "Found {$total} product record(s) (page {$page} of {$lastPage}).";
                continue;
            }

            if ($type === 'list_user_records') {
                $total = (int) ($result['total'] ?? 0);
                $page = (int) ($result['current_page'] ?? 1);
                $lastPage = (int) ($result['last_page'] ?? 1);
                $summaries[] = "Found {$total} user record(s) (page {$page} of {$lastPage}).";
                continue;
            }

            if ($type === 'financial_report') {
                $orders = (int) ($result['total_orders'] ?? 0);
                $revenue = (float) ($result['total_revenue'] ?? 0);
                $summaries[] = 'Financial report generated: '
                    .$orders.' order(s), total revenue '.number_format($revenue, 2).'.';
                continue;
            }

            if ($type === 'task_created') {
                $id = (string) ($result['id'] ?? '');
                $title = (string) ($result['title'] ?? 'task');
                $summaries[] = $id !== ''
                    ? "Task \"{$title}\" was created (ID: {$id})."
                    : "Task \"{$title}\" was created.";
                continue;
            }

            $message = trim((string) ($result['message'] ?? ''));
            if ($message !== '') {
                $summaries[] = ucfirst(str_replace('_', ' ', $tool)).': '.$message;
                continue;
            }

            if ($type !== '') {
                $summaries[] = ucfirst(str_replace('_', ' ', $tool))." completed ({$type}).";
            }
        }

        $summaries = array_values(array_unique(array_filter($summaries, fn (string $line): bool => $line !== '')));
        if ($summaries === []) {
            return null;
        }

        return implode(' ', array_slice($summaries, 0, 4));
    }

    /**
     * @param array<int, array{tool: string, result: array<string, mixed>}> $successfulToolResults
     */
    private function alignFinalContentWithToolResults(string $content, array $successfulToolResults): ?string
    {
        $lower = strtolower($content);

        $productResult = collect($successfulToolResults)
            ->map(fn (array $row): array => $row['result'] ?? [])
            ->first(fn (array $result): bool => ($result['type'] ?? null) === 'list_product_records');

        if (is_array($productResult) && $this->contentDeniesProductTool($lower)) {
            $rows = is_array($productResult['rows'] ?? null) ? $productResult['rows'] : [];
            $total = (int) ($productResult['total'] ?? count($rows));
            $stock = collect($rows)
                ->sum(fn ($row): float => (float) (is_array($row) ? ($row['quantity'] ?? 0) : 0));

            return "I found {$total} products with total stock {$stock}.";
        }

        $orderRows = [];
        foreach ($successfulToolResults as $row) {
            $result = is_array($row['result'] ?? null) ? $row['result'] : [];
            if (($result['type'] ?? null) === 'order_detail' && is_array($result['order'] ?? null)) {
                $orderRows[] = $result['order'];
            }
            if (($result['type'] ?? null) === 'orders_table' && is_array($result['orders'] ?? null)) {
                foreach ($result['orders'] as $order) {
                    if (is_array($order)) {
                        $orderRows[] = $order;
                    }
                }
            }
        }

        if ($orderRows !== [] && $this->contentClaimsOrderNotFound($lower)) {
            $first = $orderRows[0];
            $orderNo = (string) ($first['order_no'] ?? $first['id'] ?? 'N/A');
            $status = (string) ($first['status'] ?? 'N/A');
            $count = count($orderRows);

            return "I found {$count} matching order(s). Example: {$orderNo} (status: {$status}).";
        }

        return null;
    }

    private function contentDeniesProductTool(string $lowerContent): bool
    {
        return str_contains($lowerContent, "don't have a direct product")
            || str_contains($lowerContent, 'do not have a direct product')
            || str_contains($lowerContent, 'tool is unavailable')
            || str_contains($lowerContent, 'unknown tool: list_product_records');
    }

    private function contentClaimsOrderNotFound(string $lowerContent): bool
    {
        return str_contains($lowerContent, 'not found')
            || str_contains($lowerContent, 'empty result')
            || str_contains($lowerContent, 'returned an empty result');
    }

    private function latestUserPrompt(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $message = $messages[$i];
            if ((string) ($message['role'] ?? '') !== 'user') {
                continue;
            }

            $content = trim((string) ($message['content'] ?? ''));
            if ($content !== '') {
                return $content;
            }
        }

        return '';
    }

    private function inferRequestDomain(string $prompt): ?string
    {
        $lower = Str::lower($prompt);
        if ($lower === '') {
            return null;
        }

        if (
            str_contains($lower, 'whatsapp')
            || str_contains($lower, 'whats app')
            || str_contains($lower, 'wa message')
            || str_contains($lower, 'whatsapp message')
        ) {
            return 'whatsapp';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function domainMismatchError(string $toolName, array $args): ?string
    {
        if ($this->requestDomain !== 'whatsapp') {
            return null;
        }

        $normalizedTool = Str::lower($toolName);
        $blockedForWhatsapp = [
            'list_orders',
            'get_order',
            'create_order',
            'edit_order',
            'financial_report',
            'create_report_task',
            'get_report_task_status',
            'list_sheet_records',
            'get_sheet_record',
        ];

        if (in_array($normalizedTool, $blockedForWhatsapp, true) || str_contains($normalizedTool, 'sheet')) {
            return 'Blocked domain-mismatched tool for WhatsApp request. Use whatsapp model/table tools instead.';
        }

        if ($normalizedTool === 'model_schema_workspace') {
            $action = Str::lower((string) ($args['action'] ?? ''));
            $model = Str::lower((string) ($args['model'] ?? ''));
            $table = Str::lower((string) ($args['table'] ?? ''));

            if (in_array($action, ['describe_model', 'scaffold_tools'], true)) {
                $mentionsWhatsapp = str_contains($model, 'whatsapp') || str_contains($table, 'whatsapp');
                if (($model !== '' || $table !== '') && ! $mentionsWhatsapp) {
                    return 'Blocked model_schema_workspace call outside WhatsApp domain. Use model=Whatsapp or table=whatsapp.';
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $executedToolNames
     */
    private function shouldUseProductInventoryFallback(string $latestUserPrompt, array $executedToolNames): bool
    {
        if ($latestUserPrompt === '') {
            return false;
        }

        $isInventoryIntent = preg_match('/\b(product|products|stock|inventory|quantity|quantities)\b/i', $latestUserPrompt) === 1;
        if (! $isInventoryIntent) {
            return false;
        }

        return ! collect($executedToolNames)->contains(fn (string $tool): bool => in_array($tool, [
            'list_product_records',
            'get_product_record',
        ], true));
    }

    /**
     * @param array<int, array{tool: string, type: mixed, ok: mixed, message?: mixed}> $toolOutcomeSummary
     * @param array<int, string> $executedToolNames
     */
    private function runProductInventoryFallback(callable $emit, array &$toolOutcomeSummary, array &$executedToolNames): ?string
    {
        $toolName = 'list_product_records';
        $toolArgs = ['page' => 1, 'per_page' => 100];

        $emit('tool_call', ['tool' => $toolName, 'args' => $toolArgs]);
        $result = $this->orchestrator->execute(
            $toolName,
            $toolArgs,
            fn (string $name, array $args): array => $this->dispatchTool($name, $args),
        );

        $critique = $this->critic->evaluate($toolName, $result);
        $result['_critic'] = $critique;

        $emit('tool_result', [
            'tool' => $toolName,
            'result' => $result,
        ]);

        $executedToolNames[] = $toolName;
        $toolOutcomeSummary[] = [
            'tool' => $toolName,
            'type' => $result['type'] ?? null,
            'ok' => $critique['ok'],
            'message' => $result['message'] ?? null,
        ];

        if (($result['type'] ?? null) === 'list_product_records') {
            $rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
            $productCount = (int) ($result['total'] ?? count($rows));
            $totalStock = collect($rows)
                ->sum(fn ($row): float => (float) (is_array($row) ? ($row['quantity'] ?? 0) : 0));

            return "I found {$productCount} products. Combined stock in the listed results is {$totalStock}.";
        }

        $message = trim((string) ($result['message'] ?? ''));
        if ($message === '' && is_array($result['last_error'] ?? null)) {
            $message = trim((string) ($result['last_error']['message'] ?? ''));
        }

        if (str_contains($message, 'SQLSTATE[HY000] [2002]')) {
            return 'I could not fetch product stock because the database is currently unreachable (MySQL connection failed).';
        }

        if ($message !== '') {
            return "I could not fetch product stock: {$message}";
        }

        return null;
    }

    // ── Tool dispatcher ──────────────────────────────────────────────────────

    private function dispatchTool(string $name, array $args): array
    {
        return match ($name) {
            'list_orders'  => $this->listOrders($args),
            'get_order'    => $this->getOrder($args),
            'create_order' => $this->createOrder($args),
            'edit_order'   => $this->editOrder($args),
            'scaffold_mcp_tool' => $this->scaffoldMcpTool($args),
            'model_schema_workspace' => $this->modelSchemaWorkspace($args),
            'financial_report' => $this->financialReport($args),
            'create_report_task' => $this->createReportTask($args),
            'get_report_task_status' => $this->getReportTaskStatus($args),
            'setup_integration' => $this->setupIntegration($args),
            'send_whatsapp_message' => $this->sendWhatsappMessage($args),
            'create_task' => $this->createTask($args),
            'send_email' => $this->sendEmail($args),
            'send_grid_email' => $this->sendEmail($args),
            default        => $this->invokeDynamicTool($name, $args),
        };
    }

    /**
     * Execute one tool call directly (used by background task workers).
     */
    public function callTool(string $name, array $args = []): array
    {
        $policy = $this->policy->authorize($name, $args);
        if (! $policy['allowed']) {
            $result = [
                'type' => 'policy_blocked',
                'tool' => $name,
                'risk' => $policy['risk'],
                'message' => $policy['reason'],
            ];
        } else {
            $result = $this->orchestrator->execute(
                $name,
                $args,
                fn (string $toolName, array $toolArgs): array => $this->dispatchTool($toolName, $toolArgs),
            );
        }

        $result['_policy'] = [
            'risk' => $policy['risk'],
            'requires_confirmation' => $policy['requires_confirmation'],
        ];
        $result['_critic'] = $this->critic->evaluate($name, $result);

        return $result;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function invokeDynamicTool(string $name, array $args): array
    {
        $normalized = strtolower(str_replace(['_', '-'], '', $name));
        $toolClass = $this->mcpToolMap[$name]
            ?? $this->mcpToolMap[strtolower($name)]
            ?? $this->mcpToolMap[$normalized]
            ?? null;

        if (! is_string($toolClass) || $toolClass === '') {
            // Registry may be stale after scaffold/overwrite; refresh once and retry lookup.
            $this->refreshDynamicToolRegistry();
            $toolClass = $this->mcpToolMap[$name]
                ?? $this->mcpToolMap[strtolower($name)]
                ?? $this->mcpToolMap[$normalized]
                ?? null;
        }

        if (! is_string($toolClass) || $toolClass === '') {
            $toolClass = $this->resolveToolClassFromName($name);
        }

        if (is_string($toolClass) && $toolClass !== '') {
            $this->ensureSkillFileForToolClass($toolClass, "Use when tasks require {$name}.");
            return $this->mcpInvoker->invoke($toolClass, $args);
        }

        return ['type' => 'error', 'message' => "Unknown tool: {$name}"];
    }

    private function resolveToolClassFromName(string $name): ?string
    {
        $base = trim($name);
        if ($base === '') {
            return null;
        }

        $candidates = [
            'App\\Mcp\\Tools\\'.Str::studly(str_replace('-', '_', $base)).'Tool',
            'App\\Mcp\\Tools\\'.Str::studly(str_replace(['-', '_'], ' ', $base)).'Tool',
            'App\\Mcp\\Tools\\'.Str::studly($base).'Tool',
        ];

        foreach ($candidates as $candidate) {
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function modelSchemaWorkspace(array $args): array
    {
        return $this->mcpInvoker->invoke(ModelSchemaWorkspaceTool::class, $args);
    }

    private function refreshDynamicToolRegistry(): void
    {
        if (! $this->dynamicToolsEnabled) {
            return;
        }

        try {
            $registry = app(DynamicToolRegistryService::class)->build($this->tools);
            $this->tools = is_array($registry['tools'] ?? null) ? $registry['tools'] : $this->tools;
            $this->mcpToolMap = is_array($registry['mcp_map'] ?? null) ? $registry['mcp_map'] : [];
        } catch (\Throwable $e) {
            Log::warning('Failed to build dynamic tool registry; falling back to static tools.', [
                'trace_id' => $this->traceId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{path: string, created: bool}
     */
    private function ensureSkillFileForToolClass(string $className, string $description): array
    {
        $toolBase = class_basename($className);
        $skillBase = Str::kebab(preg_replace('/Tool$/', '', $toolBase) ?? $toolBase);
        $skillsRoot = (string) config('services.ollama.skills_path', resource_path('ai/skills'));
        $skillDir = rtrim($skillsRoot, '/').'/'.$skillBase;
        $skillPath = $skillDir.'/SKILL.md';

        if (File::exists($skillPath)) {
            return ['path' => $skillPath, 'created' => false];
        }

        File::ensureDirectoryExists($skillDir);

        $toolFunction = Str::snake(preg_replace('/Tool$/', '', $toolBase) ?? $toolBase);
        $skillDescription = trim($description) !== '' ? trim($description) : "Use when tasks require {$toolFunction}.";
        $triggers = implode(', ', [$skillBase, str_replace('-', ' ', $skillBase), $toolFunction]);
        $content = <<<MD
---
name: {$skillBase}
description: {$skillDescription}
triggers: {$triggers}
---

# {$toolBase} Skill

Use this skill when the task maps to the `{$toolFunction}` tool.

## Workflow
- Prefer one focused tool call over repeated broad calls.
- Use only parameters required to satisfy the request.
- Do not claim success unless tool output confirms success.

## Output
- Return exact values from tool results.
- Include any constraints or filters used.
MD;

        File::put($skillPath, $content);

        return ['path' => $skillPath, 'created' => true];
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function renderPlanDirective(array $plan): string
    {
        $goal = trim((string) ($plan['goal'] ?? ''));
        $steps = is_array($plan['steps'] ?? null) ? $plan['steps'] : [];
        $lines = ['Execution Plan:'];

        if ($goal !== '') {
            $lines[] = 'Goal: '.$goal;
        }

        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }

            $index = (int) ($step['step'] ?? 0);
            $action = trim((string) ($step['action'] ?? ''));
            $tool = trim((string) ($step['tool'] ?? ''));
            $risk = trim((string) ($step['risk'] ?? 'medium'));

            $label = $index > 0 ? (string) $index : '-';
            $summary = $action !== '' ? $action : 'Execute planned step';
            $toolPart = $tool !== '' ? " (tool: {$tool})" : '';
            $lines[] = "{$label}. {$summary}{$toolPart} [risk: {$risk}]";
        }

        return implode("\n", $lines);
    }

    // ── Tool implementations ─────────────────────────────────────────────────

    private function listOrders(array $args): array
    {
        $page    = max(1, (int) ($args['page'] ?? 1));
        $perPage = min(50, max(5, (int) ($args['per_page'] ?? 15)));

        $query = SheetOrder::query()->latest('order_date');

        if (! empty($args['status'])) {
            $query->where('status', $args['status']);
        }
        if (! empty($args['client_name'])) {
            $query->where('client_name', 'like', '%' . $args['client_name'] . '%');
        }
        if (! empty($args['merchant'])) {
            $query->where('merchant', 'like', '%' . $args['merchant'] . '%');
        }
        if (! empty($args['phone'])) {
            $query->where('phone', 'like', '%' . $args['phone'] . '%');
        }
        if (! empty($args['code'])) {
            $query->where('code', 'like', '%' . $args['code'] . '%');
        }
        if (array_key_exists('code_is_empty', $args)) {
            $codeIsEmpty = filter_var($args['code_is_empty'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($codeIsEmpty === true) {
                $query->where(function ($q) {
                    $q->whereNull('code')->orWhere('code', '');
                });
            } elseif ($codeIsEmpty === false) {
                $query->whereNotNull('code')->where('code', '!=', '');
            }
        }
        if (! empty($args['alt_no'])) {
            $query->where('alt_no', 'like', '%' . $args['alt_no'] . '%');
        }
        if (! empty($args['agent'])) {
            $query->where('agent', $args['agent']);
        }
        if (! empty($args['city'])) {
            $query->where('city', 'like', '%' . $args['city'] . '%');
        }
        if (! empty($args['country'])) {
            $query->where('country', $args['country']);
        }
        if (! empty($args['search'])) {
            $s = $args['search'];
            $query->where(function ($q) use ($s) {
                $q->where('order_no', 'like', "%{$s}%")
                  ->orWhere('product_name', 'like', "%{$s}%")
                  ->orWhere('client_name', 'like', "%{$s}%")
                  ->orWhere('merchant', 'like', "%{$s}%")
                  ->orWhere('city', 'like', "%{$s}%")
                  ->orWhere('agent', 'like', "%{$s}%")
                  ->orWhere('phone', 'like', "%{$s}%")
                  ->orWhere('code', 'like', "%{$s}%")
                  ->orWhere('alt_no', 'like', "%{$s}%")
                  ->orWhere('store_name', 'like', "%{$s}%");
            });
        }

        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'type'         => 'orders_table',
            'total'        => $paginated->total(),
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
            'per_page'     => $paginated->perPage(),
            'orders'       => collect($paginated->items())
                                ->map(fn ($o) => $o->toArray())
                                ->values()
                                ->all(),
        ];
    }

    private function getOrder(array $args): array
    {
        if (empty($args['id']) && empty($args['order_no'])) {
            return ['type' => 'error', 'message' => 'Provide id or order_no.'];
        }

        $order = isset($args['id'])
            ? SheetOrder::find((int) $args['id'])
            : SheetOrder::where('order_no', $args['order_no'])->first();

        if (! $order) {
            return ['type' => 'error', 'message' => 'Order not found.'];
        }

        return [
            'type'  => 'order_detail',
            'order' => $order->toArray(),
        ];
    }

    private function createOrder(array $args): array
    {
        $validator = \Illuminate\Support\Facades\Validator::make($args, [
            'order_no'     => 'required|string|unique:sheet_orders,order_no',
            'client_name'  => 'required|string',
            'product_name' => 'required|string',
            'amount'       => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return [
                'type'    => 'error',
                'message' => $validator->errors()->first(),
            ];
        }

        try {
            $payload = Arr::except($args, ['confirmed']);
            $order = SheetOrder::create($payload);
        } catch (\Throwable $e) {
            return ['type' => 'error', 'message' => 'DB error: ' . $e->getMessage()];
        }

        return [
            'type'    => 'order_created',
            'message' => "Order #{$order->order_no} created successfully.",
            'order'   => $order->toArray(),
        ];
    }

    private function editOrder(array $args): array
    {
        if (empty($args['id']) && empty($args['order_no'])) {
            return ['type' => 'error', 'message' => 'Provide id or order_no to identify the order.'];
        }

        $order = isset($args['id'])
            ? SheetOrder::find((int) $args['id'])
            : SheetOrder::where('order_no', $args['order_no'])->first();

        if (! $order) {
            return ['type' => 'error', 'message' => 'Order not found.'];
        }

        try {
            $order->update(
                collect($args)->except(['id', 'order_no', 'confirmed'])->toArray()
            );
        } catch (\Throwable $e) {
            return ['type' => 'error', 'message' => 'DB error: ' . $e->getMessage()];
        }

        return [
            'type'    => 'order_updated',
            'message' => "Order #{$order->order_no} updated successfully.",
            'order'   => $order->fresh()->toArray(),
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Break text into small chunks and emit as 'delta' events
     * so the frontend gets a typewriter / streaming effect.
     */
    private function emitStreamedText(string $text, callable $emit): void
    {
        if ($text === '') {
            return;
        }

        // Split by words to avoid breaking mid-character in multibyte strings
        $words  = preg_split('/(\s+)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $buffer = '';

        foreach ($words as $word) {
            $buffer .= $word;
            if (strlen($buffer) >= 6) {
                $emit('delta', ['content' => $buffer]);
                $buffer = '';
            }
        }

        if ($buffer !== '') {
            $emit('delta', ['content' => $buffer]);
        }
    }

    /**
     * @param array<string, mixed>|null $responseJson
     * @return array<string, int|float>
     */
    private function buildContextUsagePayload(?array $responseJson): array
    {
        $payload = is_array($responseJson) ? $responseJson : [];
        $promptEvalCount = max(0, (int) ($payload['prompt_eval_count'] ?? 0));
        $evalCount = max(0, (int) ($payload['eval_count'] ?? 0));
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

    /**
     * Keep tool feedback compact and JSON-safe before feeding it back into the model context.
     *
     * @param array<string, mixed> $result
     */
    private function compactToolResultForModel(array $result): string
    {
        $summary = [
            'type' => $result['type'] ?? 'unknown',
            'message' => $result['message'] ?? null,
            'tool' => $result['tool'] ?? null,
            'table' => $result['table'] ?? null,
            'total' => $result['total'] ?? null,
            'current_page' => $result['current_page'] ?? null,
            'last_page' => $result['last_page'] ?? null,
            'rows_count' => is_array($result['rows'] ?? null) ? count($result['rows']) : null,
            'orders_count' => is_array($result['orders'] ?? null) ? count($result['orders']) : null,
            'available_tool_functions' => is_array($result['available_tool_functions'] ?? null)
                ? array_values(array_slice($result['available_tool_functions'], 0, 12))
                : null,
            'created_count' => is_array($result['created'] ?? null) ? count($result['created']) : null,
            'skipped_count' => is_array($result['skipped'] ?? null) ? count($result['skipped']) : null,
            'last_error' => is_array($result['last_error'] ?? null)
                ? [
                    'type' => $result['last_error']['type'] ?? null,
                    'message' => $result['last_error']['message'] ?? null,
                ]
                : null,
            'details' => $result['details'] ?? null,
            'upstream_status' => $result['upstream_status'] ?? null,
        ];

        $encoded = json_encode(
            array_filter($summary, static fn ($value) => $value !== null),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        if (! is_string($encoded) || $encoded === '') {
            $encoded = '{"type":"error","message":"Unable to encode tool result summary."}';
        }

        if (strlen($encoded) > 6000) {
            $encoded = substr($encoded, 0, 6000).'...';
        }

        return $encoded;
    }

    private function scaffoldMcpTool(array $args): array
    {
        $validator = Validator::make($args, [
            'tool_name' => ['required', 'string'],
            'description' => ['required', 'string'],
            'tool_kind' => ['nullable', 'string', 'max:64'],
            'arguments' => ['nullable', 'array'],
            'arguments.*.name' => ['required_with:arguments', 'string'],
            'arguments.*.type' => ['required_with:arguments', 'string', 'in:string,integer,number,boolean,array,object'],
            'arguments.*.description' => ['nullable', 'string'],
            'arguments.*.required' => ['nullable', 'boolean'],
            'arguments.*.nullable' => ['nullable', 'boolean'],
            'task_notes' => ['nullable', 'string'],
            'register_in_orders_server' => ['nullable', 'boolean'],
            'overwrite' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return ['type' => 'error', 'message' => $validator->errors()->first()];
        }

        $rawName = trim((string) $args['tool_name']);
        $baseName = preg_replace('/Tool$/', '', $rawName) ?? $rawName;
        $classBase = Str::studly($baseName);

        if (! preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $classBase)) {
            return ['type' => 'error', 'message' => 'tool_name must be a valid PHP class identifier.'];
        }

        $className = $classBase.'Tool';
        $filePath = app_path("Mcp/Tools/{$className}.php");
        $overwrite = (bool) ($args['overwrite'] ?? false);

        if (File::exists($filePath) && ! $overwrite) {
            return ['type' => 'error', 'message' => "{$className} already exists. Set overwrite=true to replace it."];
        }

        $argumentDefs = is_array($args['arguments'] ?? null) ? $args['arguments'] : [];
        $description = trim((string) $args['description']);
        $taskNotes = trim((string) ($args['task_notes'] ?? ''));
        $toolKind = (string) ($args['tool_kind'] ?? $this->inferScaffoldToolKind($className, $description));

        $fileContents = $this->buildScaffoldedToolClass(
            className: $className,
            description: $description,
            argumentDefs: $argumentDefs,
            taskNotes: $taskNotes,
            toolKind: $toolKind,
        );

        File::ensureDirectoryExists(dirname($filePath));
        File::put($filePath, $fileContents);
        $skill = $this->ensureSkillFileForToolClass(
            className: "App\\Mcp\\Tools\\{$className}",
            description: $description,
        );

        $registered = false;
        $registerInOrdersServer = (bool) ($args['register_in_orders_server'] ?? true);
        if ($registerInOrdersServer) {
            $registered = $this->registerScaffoldedToolInOrdersServer($className);
        }

        return [
            'type' => 'tool_scaffolded',
            'message' => "{$className} created successfully.",
            'class' => "App\\Mcp\\Tools\\{$className}",
            'path' => $filePath,
            'registered_in_orders_server' => $registered,
            'skill_path' => $skill['path'],
            'skill_created' => $skill['created'],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $argumentDefs
     */
    private function buildScaffoldedToolClass(string $className, string $description, array $argumentDefs, string $taskNotes, string $toolKind): string
    {
        if ($toolKind === 'report') {
            return $this->buildScaffoldedReportToolClass($className, $description);
        }
        if ($toolKind === 'query') {
            return $this->buildScaffoldedQueryToolClass($className, $description);
        }
        if ($toolKind === 'mutation') {
            return $this->buildScaffoldedMutationToolClass($className, $description);
        }
        if ($toolKind === 'custom') {
            return $this->buildScaffoldedCustomToolClass($className, $description, $taskNotes);
        }

        $schemaLines = [];
        $validationLines = [];
        $echoArguments = [];

        foreach ($argumentDefs as $arg) {
            $name = (string) ($arg['name'] ?? '');
            $type = (string) ($arg['type'] ?? 'string');
            $argDescription = addslashes((string) ($arg['description'] ?? ''));
            $nullable = (bool) ($arg['nullable'] ?? true);
            $required = (bool) ($arg['required'] ?? false);

            if ($name === '' || ! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
                continue;
            }

            $schemaMethod = match ($type) {
                'integer' => 'integer',
                'number' => 'number',
                'boolean' => 'boolean',
                'array' => 'array',
                'object' => 'object',
                default => 'string',
            };

            $schemaExpr = "\$schema->{$schemaMethod}()->description('{$argDescription}')";
            if ($nullable) {
                $schemaExpr .= '->nullable()';
            }

            $schemaLines[] = "            '{$name}' => {$schemaExpr},";

            $rules = [];
            $rules[] = $required ? 'required' : 'nullable';
            $rules[] = match ($type) {
                'integer' => 'integer',
                'number' => 'numeric',
                'boolean' => 'boolean',
                'array' => 'array',
                'object' => 'array',
                default => 'string',
            };

            $rulesString = implode("', '", $rules);
            $validationLines[] = "            '{$name}' => ['{$rulesString}'],";
            $echoArguments[] = "'{$name}' => \$args['{$name}'] ?? null,";
        }

        if ($schemaLines === []) {
            $schemaLines[] = "            // Define tool arguments here.";
        }

        if ($validationLines === []) {
            $validationLines[] = "            // Add argument validation rules here.";
        }

        if ($echoArguments === []) {
            $echoArguments[] = "                // Return structured output for the caller.";
        }

        $taskNoteComment = $taskNotes !== ''
            ? "        // Task notes: ".str_replace("\n", ' ', addslashes($taskNotes))
            : "        // TODO: Implement the task logic.";

        $escapedDescription = addslashes($description);

        return <<<PHP
<?php

namespace App\Mcp\Tools;

use Illuminate\\Contracts\\JsonSchema\\JsonSchema;
use Illuminate\\Support\\Facades\\Validator;
use Laravel\\Mcp\\Request;
use Laravel\\Mcp\\Response;
use Laravel\\Mcp\\Server\\Tool;

class {$className} extends Tool
{
    protected string \$description = '{$escapedDescription}';

    public function schema(JsonSchema \$schema): array
    {
        return [
{$this->joinScaffoldLines($schemaLines)}
        ];
    }

    public function handle(Request \$request, Response \$response): Response
    {
        \$args = \$request->arguments();

        \$validator = Validator::make(\$args, [
{$this->joinScaffoldLines($validationLines)}
        ]);

        if (\$validator->fails()) {
            return \$response->error(\$validator->errors()->first());
        }

{$taskNoteComment}

        return \$response->text(json_encode([
            'type' => '{$this->toScaffoldType($className)}',
            'message' => '{$className} executed successfully.',
            'data' => [
{$this->joinScaffoldLines($echoArguments, 16)}
            ],
        ]));
    }
}

PHP;
    }

    private function buildScaffoldedCustomToolClass(string $className, string $description, string $taskNotes): string
    {
        $escapedDescription = addslashes($description);
        $type = $this->toScaffoldType($className);
        $taskNoteComment = $taskNotes !== ''
            ? "        // Task notes: ".str_replace("\n", ' ', addslashes($taskNotes))
            : "        // Implement custom business logic here.";

        return <<<PHP
<?php

namespace App\Mcp\Tools;

use Illuminate\\Contracts\\JsonSchema\\JsonSchema;
use Laravel\\Mcp\\Request;
use Laravel\\Mcp\\Response;
use Laravel\\Mcp\\Server\\Tool;

class {$className} extends Tool
{
    protected string \$description = '{$escapedDescription}';

    public function schema(JsonSchema \$schema): array
    {
        return [
            // Define any argument contract here (string, integer, number, boolean, array, object).
        ];
    }

    public function handle(Request \$request, Response \$response): Response
    {
        \$args = \$request->arguments();

{$taskNoteComment}

        return \$response->text(json_encode([
            'type' => '{$type}',
            'message' => '{$className} executed successfully.',
            'data' => \$args,
        ]));
    }
}

PHP;
    }

    private function buildScaffoldedQueryToolClass(string $className, string $description): string
    {
        $escapedDescription = addslashes($description);
        $type = $this->toScaffoldType($className);

        return <<<PHP
<?php

namespace App\Mcp\Tools;

use App\\Models\\SheetOrder;
use Illuminate\\Contracts\\JsonSchema\\JsonSchema;
use Illuminate\\Support\\Facades\\Validator;
use Laravel\\Mcp\\Request;
use Laravel\\Mcp\\Response;
use Laravel\\Mcp\\Server\\Tool;

class {$className} extends Tool
{
    protected string \$description = '{$escapedDescription}';

    public function schema(JsonSchema \$schema): array
    {
        return [
            'search' => \$schema->string()->description('Search across order_no, client_name, product_name, merchant')->nullable(),
            'status' => \$schema->string()->description('Status filter')->nullable(),
            'merchant' => \$schema->string()->description('Merchant filter (partial match)')->nullable(),
            'page' => \$schema->integer()->description('Page number, default 1')->nullable(),
            'per_page' => \$schema->integer()->description('Results per page, max 100, default 20')->nullable(),
        ];
    }

    public function handle(Request \$request, Response \$response): Response
    {
        \$args = \$request->arguments();

        \$validator = Validator::make(\$args, [
            'search' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'merchant' => ['nullable', 'string'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if (\$validator->fails()) {
            return \$response->error(\$validator->errors()->first());
        }

        \$page = max(1, (int) (\$args['page'] ?? 1));
        \$perPage = min(100, max(1, (int) (\$args['per_page'] ?? 20)));
        \$query = SheetOrder::query()->latest('order_date');

        if (! empty(\$args['status'])) {
            \$query->where('status', \$args['status']);
        }
        if (! empty(\$args['merchant'])) {
            \$query->where('merchant', 'like', '%'.\$args['merchant'].'%');
        }
        if (! empty(\$args['search'])) {
            \$s = \$args['search'];
            \$query->where(fn (\$q) => \$q
                ->where('order_no', 'like', "%{\$s}%")
                ->orWhere('client_name', 'like', "%{\$s}%")
                ->orWhere('product_name', 'like', "%{\$s}%")
                ->orWhere('merchant', 'like', "%{\$s}%")
            );
        }

        \$paginated = \$query->paginate(\$perPage, ['*'], 'page', \$page);

        return \$response->text(json_encode([
            'type' => '{$type}',
            'total' => \$paginated->total(),
            'current_page' => \$paginated->currentPage(),
            'last_page' => \$paginated->lastPage(),
            'per_page' => \$paginated->perPage(),
            'rows' => collect(\$paginated->items())->map->toArray()->values()->all(),
        ]));
    }
}

PHP;
    }

    private function buildScaffoldedMutationToolClass(string $className, string $description): string
    {
        $escapedDescription = addslashes($description);
        $type = $this->toScaffoldType($className);

        return <<<PHP
<?php

namespace App\Mcp\Tools;

use App\\Models\\SheetOrder;
use Illuminate\\Contracts\\JsonSchema\\JsonSchema;
use Illuminate\\Support\\Facades\\Validator;
use Laravel\\Mcp\\Request;
use Laravel\\Mcp\\Response;
use Laravel\\Mcp\\Server\\Tool;

class {$className} extends Tool
{
    protected string \$description = '{$escapedDescription}';

    public function schema(JsonSchema \$schema): array
    {
        return [
            'id' => \$schema->integer()->description('Order ID')->nullable(),
            'order_no' => \$schema->string()->description('Order number')->nullable(),
            'updates' => \$schema->object()->description('Key-value fields to update')->nullable(),
        ];
    }

    public function handle(Request \$request, Response \$response): Response
    {
        \$args = \$request->arguments();

        \$validator = Validator::make(\$args, [
            'id' => ['nullable', 'integer'],
            'order_no' => ['nullable', 'string'],
            'updates' => ['required', 'array'],
        ]);

        if (\$validator->fails()) {
            return \$response->error(\$validator->errors()->first());
        }

        if (empty(\$args['id']) && empty(\$args['order_no'])) {
            return \$response->error('Provide id or order_no to identify the record.');
        }

        \$order = isset(\$args['id'])
            ? SheetOrder::find((int) \$args['id'])
            : SheetOrder::where('order_no', \$args['order_no'])->first();

        if (! \$order) {
            return \$response->error('Order not found.');
        }

        \$order->update((array) (\$args['updates'] ?? []));

        return \$response->text(json_encode([
            'type' => '{$type}',
            'message' => '{$className} executed successfully.',
            'order' => \$order->fresh()->toArray(),
        ]));
    }
}

PHP;
    }

    private function buildScaffoldedReportToolClass(string $className, string $description): string
    {
        $escapedDescription = addslashes($description);
        $type = $this->toScaffoldType($className);

        return <<<PHP
<?php

namespace App\Mcp\Tools;

use App\\Models\\SheetOrder;
use Illuminate\\Contracts\\JsonSchema\\JsonSchema;
use Illuminate\\Support\\Facades\\Validator;
use Laravel\\Mcp\\Request;
use Laravel\\Mcp\\Response;
use Laravel\\Mcp\\Server\\Tool;

class {$className} extends Tool
{
    protected string \$description = '{$escapedDescription}';

    public function schema(JsonSchema \$schema): array
    {
        return [
            'merchant' => \$schema->string()->description('Merchant filter (partial match)')->nullable(),
            'start_date' => \$schema->string()->description('Start date (YYYY-MM-DD)')->nullable(),
            'end_date' => \$schema->string()->description('End date (YYYY-MM-DD)')->nullable(),
            'country' => \$schema->string()->description('Country filter')->nullable(),
            'city' => \$schema->string()->description('City filter (partial match)')->nullable(),
            'limit' => \$schema->integer()->description('Rows to include in listing section, max 200, default 50')->nullable(),
        ];
    }

    public function handle(Request \$request, Response \$response): Response
    {
        \$args = \$request->arguments();

        \$validator = Validator::make(\$args, [
            'merchant' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'country' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        if (\$validator->fails()) {
            return \$response->error(\$validator->errors()->first());
        }

        \$query = SheetOrder::query()->where('status', 'Delivered');

        if (! empty(\$args['merchant'])) {
            \$query->where('merchant', 'like', '%'.\$args['merchant'].'%');
        }
        if (! empty(\$args['country'])) {
            \$query->where('country', \$args['country']);
        }
        if (! empty(\$args['city'])) {
            \$query->where('city', 'like', '%'.\$args['city'].'%');
        }
        if (! empty(\$args['start_date'])) {
            \$query->whereDate('order_date', '>=', \$args['start_date']);
        }
        if (! empty(\$args['end_date'])) {
            \$query->whereDate('order_date', '<=', \$args['end_date']);
        }

        \$orders = \$query->orderByDesc('order_date')->get();

        if (\$orders->isEmpty()) {
            return \$response->error('No delivered orders found for the provided filters.');
        }

        \$amounts = \$orders->map(function (\$order) {
            \$raw = \$order->amount;
            if (is_numeric(\$raw)) {
                return (float) \$raw;
            }
            return (float) preg_replace('/[^0-9.\\-]/', '', (string) \$raw);
        });

        \$totalRevenue = \$amounts->sum();
        \$orderCount = \$orders->count();
        \$avg = \$orderCount > 0 ? \$totalRevenue / \$orderCount : 0.0;

        \$productBreakdown = \$orders
            ->groupBy(fn (\$o) => trim((string) (\$o->product_name ?? 'Unknown')))
            ->map(function (\$group, \$product) {
                \$revenue = \$group->sum(function (\$order) {
                    \$raw = \$order->amount;
                    if (is_numeric(\$raw)) {
                        return (float) \$raw;
                    }
                    return (float) preg_replace('/[^0-9.\\-]/', '', (string) \$raw);
                });
                \$count = \$group->count();

                return [
                    'product_name' => \$product,
                    'order_count' => \$count,
                    'total_revenue' => round(\$revenue, 2),
                    'average_price' => round(\$count > 0 ? \$revenue / \$count : 0.0, 2),
                ];
            })
            ->sortByDesc('order_count')
            ->values()
            ->take(8)
            ->all();

        \$cityBreakdown = \$orders
            ->groupBy(fn (\$o) => trim((string) (\$o->city ?? 'Unknown')))
            ->map(fn (\$group, \$city) => ['city' => \$city, 'order_count' => \$group->count()])
            ->sortByDesc('order_count')
            ->values()
            ->take(8)
            ->all();

        \$limit = min(200, max(1, (int) (\$args['limit'] ?? 50)));
        \$listedOrders = \$orders->take(\$limit)->map(fn (\$o) => \$o->toArray())->values()->all();

        return \$response->text(json_encode([
            'type' => '{$type}',
            'merchant' => \$args['merchant'] ?? null,
            'status' => 'Delivered',
            'filters' => [
                'country' => \$args['country'] ?? null,
                'city' => \$args['city'] ?? null,
                'start_date' => \$args['start_date'] ?? null,
                'end_date' => \$args['end_date'] ?? null,
            ],
            'total_orders' => \$orderCount,
            'total_revenue' => round(\$totalRevenue, 2),
            'average_order_value' => round(\$avg, 2),
            'product_breakdown' => \$productBreakdown,
            'city_breakdown' => \$cityBreakdown,
            'listed_orders_count' => count(\$listedOrders),
            'orders' => \$listedOrders,
        ]));
    }
}

PHP;
    }

    private function registerScaffoldedToolInOrdersServer(string $className): bool
    {
        $serverPath = app_path('Mcp/Servers/OrdersServer.php');

        if (! File::exists($serverPath)) {
            return false;
        }

        $server = File::get($serverPath);
        $fqcn = "use App\\Mcp\\Tools\\{$className};";
        $entry = "        {$className}::class,";

        if (! str_contains($server, $fqcn)) {
            $server = preg_replace('/^namespace App\\\\Mcp\\\\Servers;\n\n/m', "namespace App\\Mcp\\Servers;\n\n{$fqcn}\n", $server) ?? $server;
        }

        if (! str_contains($server, $entry)) {
            $server = preg_replace('/(protected array \$tools = \[\n)/', '$1'.$entry."\n", $server) ?? $server;
        }

        File::put($serverPath, $server);

        return true;
    }

    /**
     * @param array<int, string> $lines
     */
    private function joinScaffoldLines(array $lines, int $spaces = 12): string
    {
        $indent = str_repeat(' ', $spaces);
        return implode("\n", array_map(fn (string $line) => $indent.$line, $lines));
    }

    private function toScaffoldType(string $className): string
    {
        return Str::snake(preg_replace('/Tool$/', '', $className) ?? $className);
    }

    private function inferScaffoldToolKind(string $className, string $description): string
    {
        $blob = strtolower($className.' '.$description);
        if (str_contains($blob, 'report') || str_contains($blob, 'financial') || str_contains($blob, 'summary')) {
            return 'report';
        }
        if (str_contains($blob, 'create') || str_contains($blob, 'update') || str_contains($blob, 'edit') || str_contains($blob, 'delete')) {
            return 'mutation';
        }
        if (str_contains($blob, 'list') || str_contains($blob, 'find') || str_contains($blob, 'search') || str_contains($blob, 'query') || str_contains($blob, 'fetch')) {
            return 'query';
        }

        return 'basic';
    }

    private function financialReport(array $args): array
    {
        $query = SheetOrder::query()->where('status', 'Delivered');

        if (! empty($args['agent'])) {
            $query->where('agent', $args['agent']);
        } else {
            // Default financial reports to remitted orders (common typo included).
            $query->whereRaw('LOWER(TRIM(agent)) in (?, ?)', ['remitted', 'remittted']);
        }

        if (! empty($args['merchant'])) {
            $query->where('merchant', 'like', '%'.$args['merchant'].'%');
        }

        if (! empty($args['country'])) {
            $query->where('country', $args['country']);
        }

        if (! empty($args['city'])) {
            $query->where('city', 'like', '%'.$args['city'].'%');
        }

        if (! empty($args['start_date'])) {
            $query->whereDate('order_date', '>=', $args['start_date']);
        }

        if (! empty($args['end_date'])) {
            $query->whereDate('order_date', '<=', $args['end_date']);
        }

        $orders = $query->orderByDesc('order_date')->get();

        if ($orders->isEmpty()) {
            return [
                'type' => 'error',
                'message' => 'No delivered + remitted orders found for the provided filters.',
            ];
        }

        $amounts = $orders->map(function ($order) {
            $raw = $order->amount;
            if (is_numeric($raw)) {
                return (float) $raw;
            }

            return (float) preg_replace('/[^0-9.\-]/', '', (string) $raw);
        });

        $totalRevenue = $amounts->sum();
        $orderCount = $orders->count();
        $averageOrderValue = $orderCount > 0 ? $totalRevenue / $orderCount : 0.0;

        $productBreakdown = $orders
            ->groupBy(fn ($o) => trim((string) ($o->product_name ?? 'Unknown')))
            ->map(function ($group, $product) {
                $revenue = $group->sum(function ($order) {
                    $raw = $order->amount;
                    if (is_numeric($raw)) {
                        return (float) $raw;
                    }

                    return (float) preg_replace('/[^0-9.\-]/', '', (string) $raw);
                });

                $count = $group->count();

                return [
                    'product_name' => $product,
                    'order_count' => $count,
                    'total_revenue' => round($revenue, 2),
                    'average_price' => round($count > 0 ? $revenue / $count : 0.0, 2),
                ];
            })
            ->sortByDesc('order_count')
            ->values()
            ->take(8)
            ->all();

        $cityBreakdown = $orders
            ->groupBy(fn ($o) => trim((string) ($o->city ?? 'Unknown')))
            ->map(fn ($group, $city) => [
                'city' => $city,
                'order_count' => $group->count(),
            ])
            ->sortByDesc('order_count')
            ->values()
            ->take(8)
            ->all();

        $limit = min(200, max(1, (int) ($args['limit'] ?? 50)));
        $listedOrders = $orders->take($limit)->map(fn ($o) => $o->toArray())->values()->all();

        $earliest = $orders->min('order_date');
        $latest = $orders->max('order_date');
        $exportFilters = Arr::where([
            'merchant' => $args['merchant'] ?? null,
            'country' => $args['country'] ?? null,
            'city' => $args['city'] ?? null,
            'start_date' => $args['start_date'] ?? null,
            'end_date' => $args['end_date'] ?? null,
            'agent' => $args['agent'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
        $excelDownloadUrl = '/reports/financial/export';
        if ($exportFilters !== []) {
            $excelDownloadUrl .= '?' . http_build_query($exportFilters);
        }

        return [
            'type' => 'financial_report',
            'merchant' => $args['merchant'] ?? null,
            'filters' => [
                'country' => $args['country'] ?? null,
                'city' => $args['city'] ?? null,
                'start_date' => $args['start_date'] ?? null,
                'end_date' => $args['end_date'] ?? null,
                'agent' => $args['agent'] ?? 'remitted (default)',
            ],
            'status' => 'Delivered',
            'agent_scope' => ! empty($args['agent']) ? (string) $args['agent'] : 'remitted/remittted (default)',
            'total_orders' => $orderCount,
            'total_revenue' => round($totalRevenue, 2),
            'average_order_value' => round($averageOrderValue, 2),
            'date_range' => [
                'earliest_order_date' => $earliest,
                'latest_order_date' => $latest,
            ],
            'product_breakdown' => $productBreakdown,
            'city_breakdown' => $cityBreakdown,
            'listed_orders_count' => count($listedOrders),
            'orders' => $listedOrders,
            'excel_download_url' => $excelDownloadUrl,
        ];
    }

    private function createReportTask(array $args): array
    {
        $validator = Validator::make($args, [
            'merchants' => ['required', 'array', 'min:1'],
            'merchants.*.merchant' => ['required', 'string'],
            'merchants.*.start_date' => ['nullable', 'date_format:Y-m-d'],
            'merchants.*.end_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if ($validator->fails()) {
            return ['type' => 'error', 'message' => $validator->errors()->first()];
        }

        try {
            if ($this->userId === null) {
                return ['type' => 'error', 'message' => 'No authenticated user was found for report task creation.'];
            }

            $task = app(ReportTaskService::class)->create($args['merchants'], $this->userId);
        } catch (\Throwable $e) {
            return ['type' => 'error', 'message' => 'Failed to create report task: '.$e->getMessage()];
        }

        return [
            ...$task,
            'type' => 'task_workflow',
            'confirm_url' => route('report-tasks.confirm', ['taskId' => $task['id']]),
            'task_url' => route('report-tasks.show', ['taskId' => $task['id']]),
        ];
    }

    private function getReportTaskStatus(array $args): array
    {
        $validator = Validator::make($args, [
            'task_id' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return ['type' => 'error', 'message' => $validator->errors()->first()];
        }

        $task = app(ReportTaskService::class)->get((string) $args['task_id'], $this->userId);
        if ($task === null) {
            return ['type' => 'error', 'message' => 'Task not found.'];
        }

        return [
            ...$task,
            'type' => 'task_workflow',
            'confirm_url' => route('report-tasks.confirm', ['taskId' => $task['id']]),
            'task_url' => route('report-tasks.show', ['taskId' => $task['id']]),
        ];
    }

    private function createTask(array $args): array
    {
        if (is_string($args['execution_plan'] ?? null)) {
            $decodedPlan = json_decode((string) $args['execution_plan'], true);
            if (is_array($decodedPlan)) {
                $args['execution_plan'] = $decodedPlan;
            }
        }
        $confirmed = filter_var($args['confirmed'] ?? false, FILTER_VALIDATE_BOOL);
        if (! $confirmed && $this->executionPlanHasHighRiskTools($args['execution_plan'] ?? [])) {
            return [
                'type' => 'policy_blocked',
                'tool' => 'create_task',
                'risk' => 'high',
                'message' => 'Task includes high-risk tools (send_email/send_whatsapp_message). Explicit confirmation is required before scheduling. Re-run with confirmed=true.',
            ];
        }

        if ($confirmed) {
            $args['execution_plan'] = $this->injectHighRiskConfirmation($args['execution_plan'] ?? []);
        }

        $validator = Validator::make($args, [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'schedule_type' => ['required', 'in:immediate,one_time,recurring,event_triggered'],
            'run_at' => ['nullable', 'date'],
            'cron_expression' => ['nullable', 'string'],
            'cron_human' => ['nullable', 'string'],
            'event_condition' => ['nullable', 'string'],
            'timezone' => ['nullable', 'string'],
            'priority' => ['nullable', 'in:low,normal,high'],
            'execution_plan' => ['nullable', 'array'],
            'execution_plan.*.step' => ['nullable', 'integer'],
            'execution_plan.*.action' => ['nullable', 'string'],
            'execution_plan.*.tool' => ['nullable', 'string'],
            'execution_plan.*.tool_input' => ['nullable', 'array'],
            'execution_plan.*.input_summary' => ['nullable', 'string'],
            'execution_plan.*.depends_on' => ['nullable', 'array'],
            'expected_output' => ['nullable', 'string'],
            'original_user_request' => ['nullable', 'string'],
            'confirmed' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return [
                'type' => 'error',
                'message' => $validator->errors()->first(),
            ];
        }

        $userId = $this->userId ?? (auth()->id() ? (int) auth()->id() : null);
        if ($userId === null) {
            return [
                'type' => 'error',
                'message' => 'No authenticated user was found for task creation.',
            ];
        }

        try {
            $task = app(TaskService::class)->createFromToolCall($validator->validated(), $userId);
        } catch (\Throwable $e) {
            return [
                'type' => 'error',
                'message' => 'Failed to create task: '.$e->getMessage(),
            ];
        }

        return [
            'type' => 'task_created',
            'id' => (string) $task->id,
            'title' => (string) $task->title,
            'status' => (string) $task->status,
            'schedule_type' => (string) $task->schedule_type,
            'run_at' => $task->run_at?->toIso8601String(),
            'cron_human' => $task->cron_human,
            'next_run_at' => $task->next_run_at?->toIso8601String(),
            'task_url' => route('tasks.show', ['task' => $task->id]),
            'message' => "Task '{$task->title}' created and available at /tasks/{$task->id}.",
        ];
    }

    private function setupIntegration(array $args): array
    {
        $integration = strtolower(trim((string) ($args['integration_name'] ?? '')));
        $provider = strtolower(trim((string) ($args['provider'] ?? '')));
        $documentationUrl = trim((string) ($args['documentation_url'] ?? ''));
        $overwrite = (bool) ($args['overwrite'] ?? false);

        if ($integration === '') {
            return ['type' => 'error', 'message' => 'integration_name is required.'];
        }

        if ($integration !== 'whatsapp') {
            return [
                'type' => 'integration_requirements',
                'integration_name' => $integration,
                'message' => "Integration '{$integration}' is not scaffolded yet. Provide documentation_url so a custom integration can be generated.",
                'questions' => [
                    'Which provider/API should be used?',
                    'What authentication method is required (API key, OAuth, token)?',
                    'Share provider docs URL so request/response format can be implemented.',
                ],
                'required_inputs' => ['provider', 'documentation_url'],
            ];
        }

        $knownProviders = ['meta', 'twilio', 'africastalking', 'custom'];
        if ($provider === '') {
            return [
                'type' => 'integration_requirements',
                'integration_name' => 'whatsapp',
                'message' => 'Choose a WhatsApp provider before scaffolding (or provide any custom provider name).',
                'provider_options' => [...$knownProviders, 'any_custom_provider_name'],
                'questions' => [
                    'Which provider do you want (meta, twilio, africastalking, or any custom provider name)?',
                    'Do you want sandbox/testing mode first or production credentials?',
                ],
                'required_inputs' => ['provider'],
            ];
        }

        $isKnownProvider = in_array($provider, $knownProviders, true);
        $providerMode = $isKnownProvider ? $provider : 'custom';

        if ($providerMode === 'custom' && $documentationUrl === '') {
            return [
                'type' => 'integration_requirements',
                'integration_name' => 'whatsapp',
                'provider' => $provider,
                'message' => "Provider '{$provider}' is treated as custom. Share documentation_url (or endpoint/auth details) so request format can be implemented safely.",
                'questions' => [
                    'What is the base API URL?',
                    'What auth format is required (Bearer token, API key header, query token)?',
                    'What endpoint and payload format sends a text message?',
                ],
                'required_inputs' => ['documentation_url'],
            ];
        }

        $files = $this->scaffoldWhatsappIntegration($providerMode, $documentationUrl, $overwrite);
        $requiredEnv = $this->requiredEnvForWhatsappProvider($providerMode);
        $missingEnv = array_values(array_filter($requiredEnv, fn (string $key) => trim((string) env($key, '')) === ''));

        return [
            'type' => 'integration_setup',
            'integration_name' => 'whatsapp',
            'provider' => $provider,
            'message' => "WhatsApp integration scaffolded for provider '{$provider}'.".($isKnownProvider ? '' : ' It is wired through the custom-provider path.'),
            'files_created' => $files,
            'required_env_keys' => $requiredEnv,
            'missing_env_keys' => $missingEnv,
            'next_step' => empty($missingEnv)
                ? 'Environment keys appear set. Test by calling send_whatsapp_message tool.'
                : 'Add missing env keys, then retry sending a WhatsApp message.',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function scaffoldWhatsappIntegration(string $provider, string $documentationUrl, bool $overwrite): array
    {
        $created = [];

        $servicePath = app_path('Services/Whatsapp/WhatsappMessageSender.php');
        $toolPath = app_path('Mcp/Tools/SendWhatsappMessageTool.php');

        if (! File::exists($servicePath) || $overwrite) {
            File::ensureDirectoryExists(dirname($servicePath));
            File::put($servicePath, $this->buildWhatsappServiceClass($provider, $documentationUrl));
            $created[] = $servicePath;
        }

        if (! File::exists($toolPath) || $overwrite) {
            File::ensureDirectoryExists(dirname($toolPath));
            File::put($toolPath, $this->buildSendWhatsappToolClass());
            $created[] = $toolPath;
        }

        if ($this->registerScaffoldedToolInOrdersServer('SendWhatsappMessageTool')) {
            $created[] = app_path('Mcp/Servers/OrdersServer.php');
        }

        if ($this->ensureWhatsappServiceConfig()) {
            $created[] = config_path('services.php');
        }

        return $created;
    }

    private function buildWhatsappServiceClass(string $provider, string $documentationUrl): string
    {
        $providerValue = addslashes($provider);
        $docsLine = $documentationUrl !== ''
            ? "        // Custom documentation reference: ".addslashes($documentationUrl)."\n"
            : '';

        return <<<PHP
<?php

namespace App\Services\Whatsapp;

use Illuminate\Support\Facades\Http;

class WhatsappMessageSender
{
    public function send(string \$to, string \$message): array
    {
        \$provider = config('services.whatsapp.provider', '{$providerValue}');

        return match (\$provider) {
            'meta' => \$this->sendViaMeta(\$to, \$message),
            'twilio' => \$this->sendViaTwilio(\$to, \$message),
            'africastalking' => \$this->sendViaAfricasTalking(\$to, \$message),
            default => \$this->sendViaCustom(\$to, \$message),
        };
    }

    private function sendViaMeta(string \$to, string \$message): array
    {
        \$phoneNumberId = (string) config('services.whatsapp.meta_phone_number_id');
        \$token = (string) config('services.whatsapp.meta_access_token');

        if (\$phoneNumberId === '' || \$token === '') {
            return ['ok' => false, 'error' => 'Missing Meta WhatsApp credentials.'];
        }

        \$response = Http::withToken(\$token)
            ->acceptJson()
            ->post("https://graph.facebook.com/v20.0/{\$phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => \$to,
                'type' => 'text',
                'text' => ['body' => \$message],
            ]);

        return ['ok' => \$response->successful(), 'status' => \$response->status(), 'body' => \$response->json() ?? \$response->body()];
    }

    private function sendViaTwilio(string \$to, string \$message): array
    {
        \$sid = (string) config('services.whatsapp.twilio_account_sid');
        \$token = (string) config('services.whatsapp.twilio_auth_token');
        \$from = (string) config('services.whatsapp.twilio_from');

        if (\$sid === '' || \$token === '' || \$from === '') {
            return ['ok' => false, 'error' => 'Missing Twilio WhatsApp credentials.'];
        }

        \$response = Http::asForm()
            ->withBasicAuth(\$sid, \$token)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{\$sid}/Messages.json", [
                'From' => \$from,
                'To' => \$to,
                'Body' => \$message,
            ]);

        return ['ok' => \$response->successful(), 'status' => \$response->status(), 'body' => \$response->json() ?? \$response->body()];
    }

    private function sendViaAfricasTalking(string \$to, string \$message): array
    {
        \$username = (string) config('services.whatsapp.africastalking_username');
        \$apiKey = (string) config('services.whatsapp.africastalking_api_key');
        \$from = (string) config('services.whatsapp.africastalking_from');

        if (\$username === '' || \$apiKey === '') {
            return ['ok' => false, 'error' => 'Missing Africa\\'s Talking credentials.'];
        }

        \$response = Http::asForm()
            ->withHeaders(['apiKey' => \$apiKey, 'Accept' => 'application/json'])
            ->post('https://api.africastalking.com/version1/messaging', [
                'username' => \$username,
                'to' => \$to,
                'message' => \$message,
                'from' => \$from,
            ]);

        return ['ok' => \$response->successful(), 'status' => \$response->status(), 'body' => \$response->json() ?? \$response->body()];
    }

    private function sendViaCustom(string \$to, string \$message): array
    {
{$docsLine}        \$baseUrl = rtrim((string) config('services.whatsapp.custom_base_url'), '/');
        \$apiKey = (string) config('services.whatsapp.custom_api_key');

        if (\$baseUrl === '' || \$apiKey === '') {
            return ['ok' => false, 'error' => 'Custom provider selected. Set WHATSAPP_CUSTOM_BASE_URL and WHATSAPP_CUSTOM_API_KEY.'];
        }

        // Generic fallback contract: POST {base}/messages with "to" and "message".
        \$response = Http::withToken(\$apiKey)
            ->acceptJson()
            ->post(\$baseUrl.'/messages', [
                'to' => \$to,
                'message' => \$message,
            ]);

        return ['ok' => \$response->successful(), 'status' => \$response->status(), 'body' => \$response->json() ?? \$response->body()];
    }
}

PHP;
    }

    private function buildSendWhatsappToolClass(): string
    {
        return <<<'PHP'
<?php

namespace App\Mcp\Tools;

use App\Services\Whatsapp\WhatsappMessageSender;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class SendWhatsappMessageTool extends Tool
{
    protected string $description = 'Send a WhatsApp message using the configured provider.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'to' => $schema->string()->description('Recipient number in international format, e.g. +2547...'),
            'message' => $schema->string()->description('Message body to send'),
            'confirmed' => $schema->boolean()->nullable()->description('Explicit confirmation for high-risk action'),
        ];
    }

    public function handle(Request $request, Response $response): Response
    {
        $args = $request->arguments();

        $validator = Validator::make($args, [
            'to' => ['required', 'string', 'min:8'],
            'message' => ['required', 'string', 'min:1', 'max:4096'],
            'confirmed' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $response->error($validator->errors()->first());
        }

        $sender = app(WhatsappMessageSender::class);
        $result = $sender->send((string) $args['to'], (string) $args['message']);

        if (! ($result['ok'] ?? false)) {
            return $response->error((string) ($result['error'] ?? 'Failed to send WhatsApp message.'));
        }

        return $response->text(json_encode([
            'type' => 'whatsapp_message_sent',
            'to' => $args['to'],
            'provider' => config('services.whatsapp.provider'),
            'result' => $result,
        ]));
    }
}
PHP;
    }

    private function ensureWhatsappServiceConfig(): bool
    {
        $path = config_path('services.php');
        if (! File::exists($path)) {
            return false;
        }

        $contents = File::get($path);
        if (str_contains($contents, "'whatsapp' => [")) {
            return false;
        }

        $insert = <<<'PHP'

    'whatsapp' => [
        'provider' => env('WHATSAPP_PROVIDER', 'meta'),
        'meta_phone_number_id' => env('WHATSAPP_META_PHONE_NUMBER_ID'),
        'meta_access_token' => env('WHATSAPP_META_ACCESS_TOKEN'),
        'twilio_account_sid' => env('WHATSAPP_TWILIO_ACCOUNT_SID'),
        'twilio_auth_token' => env('WHATSAPP_TWILIO_AUTH_TOKEN'),
        'twilio_from' => env('WHATSAPP_TWILIO_FROM'),
        'africastalking_username' => env('WHATSAPP_AT_USERNAME'),
        'africastalking_api_key' => env('WHATSAPP_AT_API_KEY'),
        'africastalking_from' => env('WHATSAPP_AT_FROM'),
        'custom_base_url' => env('WHATSAPP_CUSTOM_BASE_URL'),
        'custom_api_key' => env('WHATSAPP_CUSTOM_API_KEY'),
    ],
PHP;

        $updated = preg_replace('/\n\];\s*$/', $insert."\n\n];", $contents);
        if (! is_string($updated)) {
            return false;
        }

        File::put($path, $updated);
        return true;
    }

    /**
     * @return array<int, string>
     */
    private function requiredEnvForWhatsappProvider(string $provider): array
    {
        return match ($provider) {
            'meta' => ['WHATSAPP_PROVIDER', 'WHATSAPP_META_PHONE_NUMBER_ID', 'WHATSAPP_META_ACCESS_TOKEN'],
            'twilio' => ['WHATSAPP_PROVIDER', 'WHATSAPP_TWILIO_ACCOUNT_SID', 'WHATSAPP_TWILIO_AUTH_TOKEN', 'WHATSAPP_TWILIO_FROM'],
            'africastalking' => ['WHATSAPP_PROVIDER', 'WHATSAPP_AT_USERNAME', 'WHATSAPP_AT_API_KEY'],
            default => ['WHATSAPP_PROVIDER', 'WHATSAPP_CUSTOM_BASE_URL', 'WHATSAPP_CUSTOM_API_KEY'],
        };
    }

    private function sendWhatsappMessage(array $args): array
    {
        $validator = Validator::make($args, [
            'to' => ['required', 'string', 'min:8'],
            'message' => ['required', 'string', 'min:1', 'max:4096'],
            'confirmed' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return ['type' => 'error', 'message' => $validator->errors()->first()];
        }

        try {
            $sender = app(WhatsappMessageSender::class);
            $result = $sender->send((string) $args['to'], (string) $args['message']);
        } catch (\Throwable $e) {
            return ['type' => 'error', 'message' => 'Failed to call WhatsApp sender: '.$e->getMessage()];
        }

        if (! ($result['ok'] ?? false)) {
            $details = null;
            if (array_key_exists('body', $result)) {
                $details = is_array($result['body']) ? json_encode($result['body']) : (string) $result['body'];
                if (is_string($details) && strlen($details) > 400) {
                    $details = substr($details, 0, 400).'...';
                }
            }

            return [
                'type' => 'error',
                'message' => (string) ($result['error'] ?? 'Failed to send WhatsApp message.'),
                'details' => $details,
                'upstream_status' => $result['status'] ?? null,
            ];
        }

        return [
            'type' => 'whatsapp_message_sent',
            'to' => (string) $args['to'],
            'provider' => (string) config('services.whatsapp.provider'),
            'result' => $result,
        ];
    }

    private function sendEmail(array $args): array
    {
        $validator = Validator::make($args, [
            'to' => ['required', 'email'],
            'subject' => ['required', 'string', 'min:1', 'max:255'],
            'content' => ['required', 'string', 'min:1'],
            'content_type' => ['nullable', 'in:text/plain,text/html'],
            'from_email' => ['nullable', 'email'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'confirmed' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return ['type' => 'error', 'message' => $validator->errors()->first()];
        }

        $apiKey = trim((string) config('services.sendgrid.api_key'));
        if ($apiKey === '') {
            return ['type' => 'error', 'message' => 'Missing SENDGRID_API_KEY.'];
        }

        $fromEmail = (string) ($args['from_email'] ?? config('services.sendgrid.from_email', config('mail.from.address')));
        $fromName = (string) ($args['from_name'] ?? config('services.sendgrid.from_name', config('mail.from.name')));
        if (trim($fromEmail) === '') {
            return ['type' => 'error', 'message' => 'Missing sender email. Set SENDGRID_FROM_EMAIL or pass from_email.'];
        }

        $payload = [
            'personalizations' => [[
                'to' => [['email' => (string) $args['to']]],
            ]],
            'from' => array_filter([
                'email' => $fromEmail,
                'name' => $fromName !== '' ? $fromName : null,
            ]),
            'subject' => (string) $args['subject'],
            'content' => [[
                'type' => (string) ($args['content_type'] ?? 'text/plain'),
                'value' => (string) $args['content'],
            ]],
        ];

        $endpoint = (string) config('services.sendgrid.endpoint', 'https://api.sendgrid.com/v3/mail/send');
        $timeout = (int) config('services.sendgrid.timeout', 15);

        try {
            $sendgridResponse = Http::timeout($timeout)
                ->withToken($apiKey)
                ->acceptJson()
                ->post($endpoint, $payload);
        } catch (\Throwable $e) {
            return ['type' => 'error', 'message' => 'SendGrid request failed: '.$e->getMessage()];
        }

        if (! $sendgridResponse->successful()) {
            $details = $sendgridResponse->json();
            if (! is_array($details)) {
                $details = ['body' => $sendgridResponse->body()];
            }

            return [
                'type' => 'error',
                'message' => 'SendGrid rejected the email request.',
                'details' => $details,
                'upstream_status' => $sendgridResponse->status(),
            ];
        }

        $messageIdHeader = $sendgridResponse->header('x-message-id');
        $messageId = is_array($messageIdHeader) ? ($messageIdHeader[0] ?? null) : $messageIdHeader;

        return [
            'type' => 'email_sent',
            'to' => (string) $args['to'],
            'subject' => (string) $args['subject'],
            'status' => $sendgridResponse->status(),
            'message_id' => is_string($messageId) && $messageId !== '' ? $messageId : null,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     */
    private function hasExplicitConfirmationMessage(array $messages): bool
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $message = $messages[$i];
            if ((string) ($message['role'] ?? '') !== 'user') {
                continue;
            }

            $text = strtolower(trim((string) ($message['content'] ?? '')));
            if ($text === '') {
                return false;
            }

            return in_array($text, [
                'yes',
                'yes.',
                'confirm',
                'confirmed',
                'i confirm',
                'go ahead',
                'proceed',
                'do it',
                'approve',
                'approved',
            ], true);
        }

        return false;
    }

    private function isHighRiskTool(string $toolName): bool
    {
        return in_array($this->policy->riskFor($toolName), ['high', 'critical'], true);
    }

    /**
     * @param mixed $executionPlan
     * @return array<int, array<string, mixed>>
     */
    private function injectHighRiskConfirmation(mixed $executionPlan): array
    {
        if (! is_array($executionPlan)) {
            return [];
        }

        $normalized = [];
        foreach ($executionPlan as $step) {
            if (! is_array($step)) {
                continue;
            }

            $toolName = (string) ($step['tool'] ?? '');
            $toolInput = $step['tool_input'] ?? [];
            if (! is_array($toolInput)) {
                $toolInput = [];
            }

            if ($toolName !== '' && $this->isHighRiskTool($toolName) && ! array_key_exists('confirmed', $toolInput)) {
                $toolInput['confirmed'] = true;
            }

            $step['tool_input'] = $toolInput;
            $normalized[] = $step;
        }

        return $normalized;
    }

    private function executionPlanHasHighRiskTools(mixed $executionPlan): bool
    {
        if (! is_array($executionPlan)) {
            return false;
        }

        foreach ($executionPlan as $step) {
            if (! is_array($step)) {
                continue;
            }

            $toolName = (string) ($step['tool'] ?? '');
            if ($toolName !== '' && $this->isHighRiskTool($toolName)) {
                return true;
            }
        }

        return false;
    }
}
