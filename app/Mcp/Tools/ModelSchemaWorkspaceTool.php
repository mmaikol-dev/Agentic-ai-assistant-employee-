<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ModelSchemaWorkspaceTool extends Tool
{
    protected string $description = 'Inspect app/Models and database table schemas, then scaffold table-specific MCP tools (list/get/create/update).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['list_models', 'describe_model', 'scaffold_tools'])->description('Action to execute'),
            'model' => $schema->string()->description('Model name or class, e.g. User or App\\Models\\User')->nullable(),
            'table' => $schema->string()->description('Table name override, e.g. users')->nullable(),
            'operations' => $schema->array()->items($schema->string()->enum(['list', 'get', 'create', 'update']))->description('For scaffold_tools: which tools to create. Default: list,get,create,update')->nullable(),
            'register_in_orders_server' => $schema->boolean()->description('Register generated tools in OrdersServer (default true)')->nullable(),
            'create_skill_file' => $schema->boolean()->description('Create SKILL.md for each generated tool when missing (default true)')->nullable(),
            'overwrite' => $schema->boolean()->description('Overwrite existing generated files (default false)')->nullable(),
        ];
    }

    public function handle(Request $request, Response $response): Response
    {
        $args = $request->arguments();

        $validator = Validator::make($args, [
            'action' => ['required', 'in:list_models,describe_model,scaffold_tools'],
            'model' => ['nullable', 'string'],
            'table' => ['nullable', 'string'],
            'operations' => ['nullable', 'array'],
            'operations.*' => ['string', 'in:list,get,create,update'],
            'register_in_orders_server' => ['nullable', 'boolean'],
            'create_skill_file' => ['nullable', 'boolean'],
            'overwrite' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $response->error($validator->errors()->first());
        }

        $action = (string) $args['action'];

        return match ($action) {
            'list_models' => $this->listModelsResponse($response),
            'describe_model' => $this->describeModelResponse($args, $response),
            'scaffold_tools' => $this->scaffoldToolsResponse($args, $response),
            default => $response->error('Unsupported action.'),
        };
    }

    private function listModelsResponse(Response $response): Response
    {
        $models = $this->discoverModels();

        return $response->text(json_encode([
            'type' => 'model_workspace',
            'action' => 'list_models',
            'count' => count($models),
            'models' => $models,
        ]));
    }

    /**
     * @param array<string, mixed> $args
     */
    private function describeModelResponse(array $args, Response $response): Response
    {
        [$className, $table] = $this->resolveClassAndTable($args['model'] ?? null, $args['table'] ?? null);

        if ($table === null || $table === '') {
            return $response->error('Could not resolve model/table. Provide model or table.');
        }

        $tableExists = $this->safeHasTable($table);
        $columns = $tableExists ? $this->safeColumnListing($table) : [];
        $modelMeta = [];

        if ($className !== null && class_exists($className) && is_subclass_of($className, Model::class)) {
            try {
                /** @var Model $instance */
                $instance = new $className();
                $modelMeta = [
                    'class' => $className,
                    'fillable' => method_exists($instance, 'getFillable') ? $instance->getFillable() : [],
                    'guarded' => method_exists($instance, 'getGuarded') ? $instance->getGuarded() : [],
                    'casts' => method_exists($instance, 'getCasts') ? $instance->getCasts() : [],
                ];
            } catch (\Throwable) {
                $modelMeta = ['class' => $className];
            }
        }

        $base = $this->baseNameFromClassOrTable($className, $table);
        $toolSuggestions = [
            'list' => "List{$base}RecordsTool",
            'get' => "Get{$base}RecordTool",
            'create' => "Create{$base}RecordTool",
            'update' => "Update{$base}RecordTool",
        ];

        return $response->text(json_encode([
            'type' => 'model_workspace',
            'action' => 'describe_model',
            'model' => $className,
            'table' => $table,
            'table_exists' => $tableExists,
            'columns' => $columns,
            'column_count' => count($columns),
            'model_meta' => $modelMeta,
            'suggested_tools' => $toolSuggestions,
        ]));
    }

    /**
     * @param array<string, mixed> $args
     */
    private function scaffoldToolsResponse(array $args, Response $response): Response
    {
        [$className, $table] = $this->resolveClassAndTable($args['model'] ?? null, $args['table'] ?? null);
        if ($table === null || $table === '') {
            return $response->error('Could not resolve model/table. Provide model or table.');
        }

        if (! $this->safeHasTable($table)) {
            return $response->error("Table '{$table}' does not exist.");
        }

        $columns = $this->safeColumnListing($table);
        if ($columns === []) {
            return $response->error("No columns found for table '{$table}'.");
        }

        $operations = is_array($args['operations'] ?? null)
            ? array_values(array_unique(array_map('strval', $args['operations'])))
            : ['list', 'get', 'create', 'update'];
        $overwrite = (bool) ($args['overwrite'] ?? false);
        $register = (bool) ($args['register_in_orders_server'] ?? true);
        $createSkillFile = (bool) ($args['create_skill_file'] ?? true);

        $base = $this->baseNameFromClassOrTable($className, $table);
        $searchableColumns = $this->searchableColumns($columns);
        $primaryKey = in_array('id', $columns, true) ? 'id' : (string) $columns[0];
        $writableColumns = array_values(array_filter($columns, fn (string $col): bool => ! in_array($col, ['id', 'created_at', 'updated_at', 'deleted_at'], true)));

        $builders = [
            'list' => fn () => [
                'class' => "List{$base}RecordsTool",
                'description' => "List and filter rows from {$table}.",
                'contents' => $this->buildListToolClass("List{$base}RecordsTool", $table, $columns, $searchableColumns),
            ],
            'get' => fn () => [
                'class' => "Get{$base}RecordTool",
                'description' => "Get one row from {$table} by key.",
                'contents' => $this->buildGetToolClass("Get{$base}RecordTool", $table, $columns, $primaryKey),
            ],
            'create' => fn () => [
                'class' => "Create{$base}RecordTool",
                'description' => "Create a row in {$table}.",
                'contents' => $this->buildCreateToolClass("Create{$base}RecordTool", $table, $writableColumns),
            ],
            'update' => fn () => [
                'class' => "Update{$base}RecordTool",
                'description' => "Update a row in {$table} by key.",
                'contents' => $this->buildUpdateToolClass("Update{$base}RecordTool", $table, $primaryKey, $writableColumns),
            ],
        ];

        $created = [];
        $skipped = [];
        $callableFunctions = [];

        foreach ($operations as $operation) {
            if (! isset($builders[$operation])) {
                continue;
            }

            /** @var array{class: string, description: string, contents: string} $payload */
            $payload = $builders[$operation]();
            $classNameOut = $payload['class'];
            $path = app_path("Mcp/Tools/{$classNameOut}.php");
            $skill = $createSkillFile
                ? $this->ensureSkillFileForTool($classNameOut, $payload['description'], $table)
                : ['path' => null, 'created' => false];
            $registered = false;
            if ($register) {
                $registered = $this->registerInOrdersServer($classNameOut);
            }

            if (File::exists($path) && ! $overwrite) {
                $skipped[] = [
                    'operation' => $operation,
                    'class' => "App\\Mcp\\Tools\\{$classNameOut}",
                    'path' => $path,
                    'reason' => 'exists',
                    'registered_in_orders_server' => $registered,
                    'skill_path' => $skill['path'],
                    'skill_created' => (bool) ($skill['created'] ?? false),
                    'function_name' => $this->toFunctionName($classNameOut),
                ];
                $callableFunctions[] = $this->toFunctionName($classNameOut);
                continue;
            }

            File::put($path, $payload['contents']);

            $created[] = [
                'operation' => $operation,
                'class' => "App\\Mcp\\Tools\\{$classNameOut}",
                'path' => $path,
                'registered_in_orders_server' => $registered,
                'skill_path' => $skill['path'],
                'skill_created' => (bool) ($skill['created'] ?? false),
                'function_name' => $this->toFunctionName($classNameOut),
            ];
            $callableFunctions[] = $this->toFunctionName($classNameOut);
        }

        return $response->text(json_encode([
            'type' => 'model_workspace',
            'action' => 'scaffold_tools',
            'model' => $className,
            'table' => $table,
            'primary_key' => $primaryKey,
            'columns' => $columns,
            'created' => $created,
            'skipped' => $skipped,
            'overwrite' => $overwrite,
            'register_in_orders_server' => $register,
            'create_skill_file' => $createSkillFile,
            'available_tool_functions' => array_values(array_unique($callableFunctions)),
        ]));
    }

    /**
     * @param mixed $modelInput
     * @param mixed $tableInput
     * @return array{0: string|null, 1: string|null}
     */
    private function resolveClassAndTable(mixed $modelInput, mixed $tableInput): array
    {
        $models = $this->discoverModels();
        $className = null;
        $table = is_string($tableInput) && trim($tableInput) !== '' ? trim($tableInput) : null;

        if (is_string($modelInput) && trim($modelInput) !== '') {
            $candidate = trim($modelInput);
            $className = str_starts_with($candidate, 'App\\Models\\') ? $candidate : 'App\\Models\\'.Str::studly($candidate);

            if (! class_exists($className) || ! is_subclass_of($className, Model::class)) {
                $className = null;
            }

            if ($className === null) {
                $matched = $this->bestModelMatch($candidate, $models);
                if (is_array($matched)) {
                    $className = (string) ($matched['class'] ?? null);
                    $table = $table ?? (is_string($matched['table'] ?? null) ? (string) $matched['table'] : null);
                }
            }
        }

        if ($table === null && $className !== null) {
            try {
                /** @var Model $instance */
                $instance = new $className();
                $table = $instance->getTable();
            } catch (\Throwable) {
                $table = null;
            }
        }

        if ($table !== null) {
            $table = $this->bestTableMatch($table, $models);
        }

        if ($className === null && $table !== null) {
            $guess = 'App\\Models\\'.Str::studly(Str::singular($table));
            if (class_exists($guess) && is_subclass_of($guess, Model::class)) {
                $className = $guess;
            }

            if ($className === null) {
                $matched = $this->bestModelMatch($table, $models);
                if (is_array($matched)) {
                    $className = (string) ($matched['class'] ?? null);
                    $table = is_string($matched['table'] ?? null) ? (string) $matched['table'] : $table;
                }
            }
        }

        return [$className, $table];
    }

    /**
     * @param array<int, array<string, mixed>> $models
     * @return array<string, mixed>|null
     */
    private function bestModelMatch(string $input, array $models): ?array
    {
        $inputNorm = $this->normalizeIdentifier($input);
        if ($inputNorm === '') {
            return null;
        }

        $best = null;
        $bestScore = 0;

        foreach ($models as $row) {
            $model = (string) ($row['model'] ?? '');
            $class = (string) ($row['class'] ?? '');
            $table = (string) ($row['table'] ?? '');
            $modelNorm = $this->normalizeIdentifier($model);
            $classNorm = $this->normalizeIdentifier(class_basename($class));
            $tableNorm = $this->normalizeIdentifier($table);
            $score = 0;

            if ($inputNorm === $modelNorm || $inputNorm === $classNorm) {
                $score = 100;
            } elseif ($tableNorm !== '' && $inputNorm === $tableNorm) {
                $score = 95;
            } elseif (
                ($modelNorm !== '' && (str_contains($inputNorm, $modelNorm) || str_contains($modelNorm, $inputNorm)))
                || ($classNorm !== '' && (str_contains($inputNorm, $classNorm) || str_contains($classNorm, $inputNorm)))
            ) {
                $score = 85;
            } elseif ($tableNorm !== '' && (str_contains($inputNorm, $tableNorm) || str_contains($tableNorm, $inputNorm))) {
                $score = 80;
            } else {
                $distance = min(
                    levenshtein($inputNorm, $modelNorm !== '' ? $modelNorm : $inputNorm),
                    levenshtein($inputNorm, $classNorm !== '' ? $classNorm : $inputNorm),
                    levenshtein($inputNorm, $tableNorm !== '' ? $tableNorm : $inputNorm),
                );

                if ($distance <= 2) {
                    $score = 70;
                } elseif ($distance <= 4) {
                    $score = 60;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
            }
        }

        return $bestScore >= 60 && is_array($best) ? $best : null;
    }

    /**
     * @param array<int, array<string, mixed>> $models
     */
    private function bestTableMatch(string $inputTable, array $models): string
    {
        $aliases = $this->tableAliases($inputTable);
        $aliasNorm = array_map(fn (string $name): string => $this->normalizeIdentifier($name), $aliases);
        $aliasNorm = array_values(array_filter($aliasNorm, fn (string $name): bool => $name !== ''));

        foreach ($models as $row) {
            $table = (string) ($row['table'] ?? '');
            if ($table === '') {
                continue;
            }

            $tableNorm = $this->normalizeIdentifier($table);
            if (in_array($tableNorm, $aliasNorm, true)) {
                return $table;
            }
        }

        return $inputTable;
    }

    /**
     * @return array<int, string>
     */
    private function tableAliases(string $table): array
    {
        $table = trim($table);
        if ($table === '') {
            return [];
        }

        $aliases = [
            $table,
            Str::snake($table),
            Str::singular($table),
            Str::plural($table),
        ];

        if (str_ends_with($table, '_messages')) {
            $aliases[] = Str::replaceLast('_messages', '', $table);
        }

        if (str_ends_with($table, 'messages')) {
            $aliases[] = preg_replace('/messages$/', '', $table) ?: $table;
        }

        return array_values(array_unique(array_filter($aliases, fn (string $name): bool => trim($name) !== '')));
    }

    private function normalizeIdentifier(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', Str::lower($value)) ?? '';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function discoverModels(): array
    {
        $dir = app_path('Models');
        if (! File::isDirectory($dir)) {
            return [];
        }

        $rows = [];

        foreach (File::files($dir) as $file) {
            $base = $file->getFilenameWithoutExtension();
            $class = 'App\\Models\\'.$base;
            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            $table = null;
            try {
                /** @var Model $instance */
                $instance = new $class();
                $table = $instance->getTable();
            } catch (\Throwable) {
                $table = null;
            }

            $rows[] = [
                'model' => $base,
                'class' => $class,
                'file' => $file->getPathname(),
                'table' => $table,
                'table_exists' => is_string($table) ? $this->safeHasTable($table) : false,
            ];
        }

        return $rows;
    }

    /**
     * @param array<int, string> $columns
     * @return array<int, string>
     */
    private function searchableColumns(array $columns): array
    {
        $preferred = ['name', 'title', 'email', 'phone', 'code', 'status', 'city', 'country', 'address', 'description'];
        $result = [];

        foreach ($columns as $column) {
            $lower = strtolower($column);
            foreach ($preferred as $keyword) {
                if (str_contains($lower, $keyword)) {
                    $result[] = $column;
                    break;
                }
            }
        }

        if ($result === []) {
            $result = array_values(array_filter($columns, fn (string $col): bool => ! in_array($col, ['id', 'created_at', 'updated_at', 'deleted_at'], true)));
        }

        return array_slice(array_values(array_unique($result)), 0, 8);
    }

    private function baseNameFromClassOrTable(?string $className, string $table): string
    {
        if (is_string($className) && $className !== '') {
            return Str::studly(class_basename($className));
        }

        return Str::studly(Str::singular($table));
    }

    /**
     * @param array<int, string> $columns
     * @param array<int, string> $searchableColumns
     */
    private function buildListToolClass(string $className, string $table, array $columns, array $searchableColumns): string
    {
        $columnsLiteral = $this->phpArrayLiteral($columns, 8);
        $searchColumnsLiteral = $this->phpArrayLiteral($searchableColumns, 8);
        $type = Str::snake(preg_replace('/Tool$/', '', $className) ?? $className);
        $tableEscaped = addslashes($table);

        return <<<PHP
<?php

namespace App\Mcp\Tools;

use Illuminate\\Contracts\\JsonSchema\\JsonSchema;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Validator;
use Laravel\\Mcp\\Request;
use Laravel\\Mcp\\Response;
use Laravel\\Mcp\\Server\\Tool;

class {$className} extends Tool
{
    protected string \$description = 'List and filter rows from {$tableEscaped}.';

    public function schema(JsonSchema \$schema): array
    {
        return [
            'search' => \$schema->string()->description('Optional keyword search across selected columns')->nullable(),
            'page' => \$schema->integer()->description('Page number, default 1')->nullable(),
            'per_page' => \$schema->integer()->description('Results per page, max 100, default 20')->nullable(),
            'order_by' => \$schema->string()->description('Sort column')->nullable(),
            'direction' => \$schema->string()->enum(['asc', 'desc'])->description('Sort direction, default desc')->nullable(),
        ];
    }

    public function handle(Request \$request, Response \$response): Response
    {
        \$args = \$request->arguments();

        \$validator = Validator::make(\$args, [
            'search' => ['nullable', 'string'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'order_by' => ['nullable', 'string'],
            'direction' => ['nullable', 'in:asc,desc'],
        ]);

        if (\$validator->fails()) {
            return \$response->error(\$validator->errors()->first());
        }

        \$columns = {$columnsLiteral};
        \$searchable = {$searchColumnsLiteral};

        \$page = max(1, (int) (\$args['page'] ?? 1));
        \$perPage = min(100, max(1, (int) (\$args['per_page'] ?? 20)));
        \$orderBy = (string) (\$args['order_by'] ?? (in_array('id', \$columns, true) ? 'id' : \$columns[0]));
        if (! in_array(\$orderBy, \$columns, true)) {
            \$orderBy = in_array('id', \$columns, true) ? 'id' : \$columns[0];
        }
        \$direction = strtolower((string) (\$args['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        \$query = DB::table('{$tableEscaped}');

        if (! empty(\$args['search']) && \$searchable !== []) {
            \$keyword = (string) \$args['search'];
            \$query->where(function (\$q) use (\$searchable, \$keyword): void {
                foreach (\$searchable as \$idx => \$column) {
                    if (\$idx === 0) {
                        \$q->where(\$column, 'like', '%'.\$keyword.'%');
                    } else {
                        \$q->orWhere(\$column, 'like', '%'.\$keyword.'%');
                    }
                }
            });
        }

        \$paginated = \$query->orderBy(\$orderBy, \$direction)->paginate(\$perPage, ['*'], 'page', \$page);

        return \$response->text(json_encode([
            'type' => '{$type}',
            'table' => '{$tableEscaped}',
            'total' => \$paginated->total(),
            'current_page' => \$paginated->currentPage(),
            'last_page' => \$paginated->lastPage(),
            'per_page' => \$paginated->perPage(),
            'rows' => collect(\$paginated->items())->map(fn (\$row) => (array) \$row)->values()->all(),
        ]));
    }
}

PHP;
    }

    /**
     * @param array<int, string> $columns
     */
    private function buildGetToolClass(string $className, string $table, array $columns, string $primaryKey): string
    {
        $columnsLiteral = $this->phpArrayLiteral($columns, 8);
        $primaryEscaped = addslashes($primaryKey);
        $tableEscaped = addslashes($table);
        $type = Str::snake(preg_replace('/Tool$/', '', $className) ?? $className);

        return <<<PHP
<?php

namespace App\Mcp\Tools;

use Illuminate\\Contracts\\JsonSchema\\JsonSchema;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Validator;
use Laravel\\Mcp\\Request;
use Laravel\\Mcp\\Response;
use Laravel\\Mcp\\Server\\Tool;

class {$className} extends Tool
{
    protected string \$description = 'Get a single row from {$tableEscaped} by key.';

    public function schema(JsonSchema \$schema): array
    {
        return [
            'primary_key' => \$schema->string()->description('Key column, default {$primaryEscaped}')->nullable(),
            'primary_value' => \$schema->string()->description('Key value to look up'),
        ];
    }

    public function handle(Request \$request, Response \$response): Response
    {
        \$args = \$request->arguments();

        \$validator = Validator::make(\$args, [
            'primary_key' => ['nullable', 'string'],
            'primary_value' => ['required', 'string'],
        ]);

        if (\$validator->fails()) {
            return \$response->error(\$validator->errors()->first());
        }

        \$columns = {$columnsLiteral};
        \$key = (string) (\$args['primary_key'] ?? '{$primaryEscaped}');
        if (! in_array(\$key, \$columns, true)) {
            return \$response->error('primary_key is not a valid column.');
        }

        \$row = DB::table('{$tableEscaped}')
            ->where(\$key, (string) \$args['primary_value'])
            ->first();

        if (! \$row) {
            return \$response->error('Record not found.');
        }

        return \$response->text(json_encode([
            'type' => '{$type}',
            'table' => '{$tableEscaped}',
            'record' => (array) \$row,
        ]));
    }
}

PHP;
    }

    /**
     * @param array<int, string> $writableColumns
     */
    private function buildCreateToolClass(string $className, string $table, array $writableColumns): string
    {
        $writableLiteral = $this->phpArrayLiteral($writableColumns, 8);
        $tableEscaped = addslashes($table);
        $type = Str::snake(preg_replace('/Tool$/', '', $className) ?? $className);

        return <<<PHP
<?php

namespace App\Mcp\Tools;

use Illuminate\\Contracts\\JsonSchema\\JsonSchema;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Validator;
use Laravel\\Mcp\\Request;
use Laravel\\Mcp\\Response;
use Laravel\\Mcp\\Server\\Tool;

class {$className} extends Tool
{
    protected string \$description = 'Create a row in {$tableEscaped}.';

    public function schema(JsonSchema \$schema): array
    {
        return [
            'data' => \$schema->object()->description('Object of column => value pairs to insert'),
        ];
    }

    public function handle(Request \$request, Response \$response): Response
    {
        \$args = \$request->arguments();

        \$validator = Validator::make(\$args, [
            'data' => ['required', 'array'],
        ]);

        if (\$validator->fails()) {
            return \$response->error(\$validator->errors()->first());
        }

        \$writable = {$writableLiteral};
        \$data = collect((array) \$args['data'])
            ->only(\$writable)
            ->all();

        if (\$data === []) {
            return \$response->error('No writable columns were provided.');
        }

        try {
            \$id = DB::table('{$tableEscaped}')->insertGetId(\$data);
            \$record = DB::table('{$tableEscaped}')->where('id', \$id)->first();
        } catch (\\Throwable \$e) {
            return \$response->error('Insert failed: '.\$e->getMessage());
        }

        return \$response->text(json_encode([
            'type' => '{$type}',
            'table' => '{$tableEscaped}',
            'id' => \$id,
            'record' => \$record ? (array) \$record : null,
        ]));
    }
}

PHP;
    }

    /**
     * @param array<int, string> $writableColumns
     */
    private function buildUpdateToolClass(string $className, string $table, string $primaryKey, array $writableColumns): string
    {
        $writableLiteral = $this->phpArrayLiteral($writableColumns, 8);
        $tableEscaped = addslashes($table);
        $primaryEscaped = addslashes($primaryKey);
        $type = Str::snake(preg_replace('/Tool$/', '', $className) ?? $className);

        return <<<PHP
<?php

namespace App\Mcp\Tools;

use Illuminate\\Contracts\\JsonSchema\\JsonSchema;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Validator;
use Laravel\\Mcp\\Request;
use Laravel\\Mcp\\Response;
use Laravel\\Mcp\\Server\\Tool;

class {$className} extends Tool
{
    protected string \$description = 'Update a row in {$tableEscaped} by key.';

    public function schema(JsonSchema \$schema): array
    {
        return [
            'primary_key' => \$schema->string()->description('Key column, default {$primaryEscaped}')->nullable(),
            'primary_value' => \$schema->string()->description('Key value to update'),
            'updates' => \$schema->object()->description('Object of column => value pairs to update'),
        ];
    }

    public function handle(Request \$request, Response \$response): Response
    {
        \$args = \$request->arguments();

        \$validator = Validator::make(\$args, [
            'primary_key' => ['nullable', 'string'],
            'primary_value' => ['required', 'string'],
            'updates' => ['required', 'array'],
        ]);

        if (\$validator->fails()) {
            return \$response->error(\$validator->errors()->first());
        }

        \$key = (string) (\$args['primary_key'] ?? '{$primaryEscaped}');
        \$writable = {$writableLiteral};
        if (! in_array(\$key, array_merge(\$writable, ['{$primaryEscaped}', 'id']), true)) {
            return \$response->error('primary_key is not valid for this table.');
        }

        \$updates = collect((array) \$args['updates'])
            ->only(\$writable)
            ->all();

        if (\$updates === []) {
            return \$response->error('No writable update columns were provided.');
        }

        try {
            \$affected = DB::table('{$tableEscaped}')
                ->where(\$key, (string) \$args['primary_value'])
                ->update(\$updates);
        } catch (\\Throwable \$e) {
            return \$response->error('Update failed: '.\$e->getMessage());
        }

        if (\$affected < 1) {
            return \$response->error('No rows were updated.');
        }

        \$record = DB::table('{$tableEscaped}')
            ->where(\$key, (string) \$args['primary_value'])
            ->first();

        return \$response->text(json_encode([
            'type' => '{$type}',
            'table' => '{$tableEscaped}',
            'updated_rows' => \$affected,
            'record' => \$record ? (array) \$record : null,
        ]));
    }
}

PHP;
    }

    /**
     * @param array<int, string> $items
     */
    private function phpArrayLiteral(array $items, int $indent = 0): string
    {
        $padding = str_repeat(' ', $indent);
        $lines = array_map(fn (string $item): string => $padding."'".addslashes($item)."'", $items);
        return "[\n".implode(",\n", $lines)."\n".str_repeat(' ', max(0, $indent - 4))."]";
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

    private function safeHasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<int, string>
     */
    private function safeColumnListing(string $table): array
    {
        try {
            return Schema::getColumnListing($table);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array{path: string, created: bool}
     */
    private function ensureSkillFileForTool(string $className, string $description, string $table): array
    {
        $skillBase = Str::kebab(preg_replace('/Tool$/', '', $className) ?? $className);
        $skillsRoot = (string) config('services.ollama.skills_path', resource_path('ai/skills'));
        $skillDir = rtrim($skillsRoot, '/').'/'.$skillBase;
        $skillPath = $skillDir.'/SKILL.md';

        if (File::exists($skillPath)) {
            return ['path' => $skillPath, 'created' => false];
        }

        File::ensureDirectoryExists($skillDir);

        $toolFunction = Str::snake(preg_replace('/Tool$/', '', $className) ?? $className);
        $skillName = $skillBase;
        $skillDescription = "Use when handling {$table} data with {$className}.";
        $triggers = implode(', ', [$skillBase, str_replace('-', ' ', $skillBase), $toolFunction, $table]);

        $content = <<<MD
---
name: {$skillName}
description: {$skillDescription}
triggers: {$triggers}
---

# {$className} Skill

Use this skill when the user asks for data or operations related to the `{$table}` table.

## Workflow
- Call `{$toolFunction}` with focused arguments first.
- If results are empty, return that clearly and suggest narrower/broader filters.
- Ground all answers in tool output; do not fabricate counts.

## Output style
- Keep the response short.
- Show exact totals when available.
- Mention filters used when summarizing counts.
MD;

        File::put($skillPath, $content);

        return ['path' => $skillPath, 'created' => true];
    }

    private function toFunctionName(string $className): string
    {
        return Str::snake(preg_replace('/Tool$/', '', $className) ?? $className);
    }
}
