<?php

namespace App\Services;

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
                    ],
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
                    ],
                ],
            ],
        ],
    ];

    public function __construct(string $model, ?string $traceId = null)
    {
        $this->model   = $model;
        $this->baseUrl = (string) config('services.ollama.base_url');
        $this->timeout = (int) config('services.ollama.timeout', 120);
        $this->traceId = $traceId;
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
                return;
            }

            if (! $response->successful()) {
                Log::warning('Ollama tool runner non-success response', [
                    'trace_id' => $this->traceId,
                    'upstream_status' => $response->status(),
                ]);

                $emit('error', [
                    'message'         => 'Ollama request failed.',
                    'details'         => $response->body(),
                    'upstream_status' => $response->status(),
                ]);
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

                // Ollama sometimes returns arguments as a JSON string
                if (is_string($toolArgs)) {
                    $toolArgs = json_decode($toolArgs, true) ?? [];
                }

                Log::debug('Ollama tool runner tool call', [
                    'trace_id' => $this->traceId,
                    'tool' => $toolName,
                    'arguments' => $toolArgs,
                ]);

                // Tell frontend which tool is being invoked
                $emit('tool_call', ['tool' => $toolName, 'args' => $toolArgs]);

                // Execute the tool
                $result = $this->dispatchTool($toolName, $toolArgs);

                Log::debug('Ollama tool runner tool result', [
                    'trace_id' => $this->traceId,
                    'tool' => $toolName,
                    'result_type' => $result['type'] ?? 'unknown',
                    'result_message' => $result['message'] ?? null,
                    'result_details' => $result['details'] ?? null,
                    'result_upstream_status' => $result['upstream_status'] ?? null,
                ]);

                // Send structured result to frontend for rich rendering
                $emit('tool_result', $result);

                // Feed the result back into the conversation
                $messages[] = [
                    'role'    => 'tool',
                    'content' => json_encode($result),
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
            'financial_report' => $this->financialReport($args),
            'create_report_task' => $this->createReportTask($args),
            'get_report_task_status' => $this->getReportTaskStatus($args),
            'setup_integration' => $this->setupIntegration($args),
            'send_whatsapp_message' => $this->sendWhatsappMessage($args),
            'send_email' => $this->sendEmail($args),
            'send_grid_email' => $this->sendEmail($args),
            default        => ['type' => 'error', 'message' => "Unknown tool: {$name}"],
        };
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
            $order = SheetOrder::create($args);
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
                collect($args)->except(['id', 'order_no'])->toArray()
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

use Illuminate\\JsonSchema\\JsonSchema;
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

use Illuminate\\JsonSchema\\JsonSchema;
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
use Illuminate\\JsonSchema\\JsonSchema;
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
use Illuminate\\JsonSchema\\JsonSchema;
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
use Illuminate\\JsonSchema\\JsonSchema;
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
            $task = app(ReportTaskService::class)->create($args['merchants']);
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

        $task = app(ReportTaskService::class)->get((string) $args['task_id']);
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
use Illuminate\JsonSchema\JsonSchema;
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
        ];
    }

    public function handle(Request $request, Response $response): Response
    {
        $args = $request->arguments();

        $validator = Validator::make($args, [
            'to' => ['required', 'string', 'min:8'],
            'message' => ['required', 'string', 'min:1', 'max:4096'],
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
}
