<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ScaffoldMcpTool extends Tool
{
    protected string $description = 'Scaffold a new MCP tool class in app/Mcp/Tools with schema, validation, and optional registration in OrdersServer.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'tool_name' => $schema->string()->description('Tool class name, e.g. "FindOrdersByRegion" (suffix "Tool" optional)'),
            'description' => $schema->string()->description('Human-readable description of what the new tool does'),
            'tool_kind' => $schema->string()->description('Tool kind (e.g. basic, query, mutation, report, custom). If omitted, inferred from name/description. Unknown values fall back to basic.')->nullable(),
            'arguments' => $schema->array()->description('Array of argument definitions: {name,type,description,required,nullable}')->nullable(),
            'task_notes' => $schema->string()->description('Optional notes inserted as comments in handle()')->nullable(),
            'register_in_orders_server' => $schema->boolean()->description('Whether to register in OrdersServer tools list (default true)')->nullable(),
            'overwrite' => $schema->boolean()->description('Overwrite file if it already exists (default false)')->nullable(),
        ];
    }

    public function handle(Request $request, Response $response): Response
    {
        $args = $request->arguments();

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
            return $response->error($validator->errors()->first());
        }

        $rawName = trim((string) $args['tool_name']);
        $baseName = preg_replace('/Tool$/', '', $rawName) ?? $rawName;
        $classBase = Str::studly($baseName);

        if (! preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $classBase)) {
            return $response->error('tool_name must be a valid PHP class identifier (letters/numbers, starting with a letter).');
        }

        $className = $classBase.'Tool';
        $filePath = app_path("Mcp/Tools/{$className}.php");
        $overwrite = (bool) ($args['overwrite'] ?? false);

        if (File::exists($filePath) && ! $overwrite) {
            return $response->error("{$className} already exists. Set overwrite=true to replace it.");
        }

        $argumentDefs = is_array($args['arguments'] ?? null) ? $args['arguments'] : [];
        $description = trim((string) $args['description']);
        $taskNotes = trim((string) ($args['task_notes'] ?? ''));
        $toolKind = (string) ($args['tool_kind'] ?? $this->inferToolKind($className, $description));

        $fileContents = $this->buildToolClass(
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
            $registered = $this->registerInOrdersServer($className);
        }

        return $response->text(json_encode([
            'type' => 'tool_scaffolded',
            'message' => "{$className} created successfully.",
            'class' => "App\\Mcp\\Tools\\{$className}",
            'path' => $filePath,
            'registered_in_orders_server' => $registered,
        ]));
    }

    /**
     * @param array<int, array<string, mixed>> $argumentDefs
     */
    private function buildToolClass(string $className, string $description, array $argumentDefs, string $taskNotes, string $toolKind): string
    {
        if ($toolKind === 'report') {
            return $this->buildReportToolClass($className, $description);
        }
        if ($toolKind === 'query') {
            return $this->buildQueryToolClass($className, $description);
        }
        if ($toolKind === 'mutation') {
            return $this->buildMutationToolClass($className, $description);
        }
        if ($toolKind === 'custom') {
            return $this->buildCustomToolClass($className, $description, $taskNotes);
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
            if ($required) {
                $rules[] = 'required';
            } else {
                $rules[] = 'nullable';
            }

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
{$this->joinLines($schemaLines)}
        ];
    }

    public function handle(Request \$request, Response \$response): Response
    {
        \$args = \$request->arguments();

        \$validator = Validator::make(\$args, [
{$this->joinLines($validationLines)}
        ]);

        if (\$validator->fails()) {
            return \$response->error(\$validator->errors()->first());
        }

{$taskNoteComment}

        return \$response->text(json_encode([
            'type' => '{$this->toSnakeType($className)}',
            'message' => '{$className} executed successfully.',
            'data' => [
{$this->joinLines($echoArguments, 16)}
            ],
        ]));
    }
}

PHP;
    }

    private function buildCustomToolClass(string $className, string $description, string $taskNotes): string
    {
        $escapedDescription = addslashes($description);
        $type = $this->toSnakeType($className);
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

    private function buildQueryToolClass(string $className, string $description): string
    {
        $escapedDescription = addslashes($description);
        $type = $this->toSnakeType($className);

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

    private function buildMutationToolClass(string $className, string $description): string
    {
        $escapedDescription = addslashes($description);
        $type = $this->toSnakeType($className);

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

    private function buildReportToolClass(string $className, string $description): string
    {
        $escapedDescription = addslashes($description);
        $type = $this->toSnakeType($className);

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

    private function registerInOrdersServer(string $className): bool
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
    private function joinLines(array $lines, int $spaces = 12): string
    {
        $indent = str_repeat(' ', $spaces);
        return implode("\n", array_map(fn (string $line) => $indent.$line, $lines));
    }

    private function toSnakeType(string $className): string
    {
        return Str::snake(preg_replace('/Tool$/', '', $className) ?? $className);
    }

    private function inferToolKind(string $className, string $description): string
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
}
