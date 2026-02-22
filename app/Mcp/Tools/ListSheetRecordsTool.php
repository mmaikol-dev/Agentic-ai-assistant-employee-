<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListSheetRecordsTool extends Tool
{
    protected string $description = 'List and filter rows from sheets.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Optional keyword search across selected columns')->nullable(),
            'page' => $schema->integer()->description('Page number, default 1')->nullable(),
            'per_page' => $schema->integer()->description('Results per page, max 100, default 20')->nullable(),
            'order_by' => $schema->string()->description('Sort column')->nullable(),
            'direction' => $schema->string()->enum(['asc', 'desc'])->description('Sort direction, default desc')->nullable(),
        ];
    }

    public function handle(Request $request, Response $response): Response
    {
        $args = $request->arguments();

        $validator = Validator::make($args, [
            'search' => ['nullable', 'string'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'order_by' => ['nullable', 'string'],
            'direction' => ['nullable', 'in:asc,desc'],
        ]);

        if ($validator->fails()) {
            return $response->error($validator->errors()->first());
        }

        $columns = [
        'id',
        'company_id',
        'store_name',
        'sheet_id',
        'sku',
        'shopify_name',
        'access_token',
        'sheet_name',
        'cc_agents',
        'created_at',
        'updated_at',
        'country'
    ];
        $searchable = [
        'store_name',
        'shopify_name',
        'sheet_name',
        'country'
    ];

        $page = max(1, (int) ($args['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($args['per_page'] ?? 20)));
        $orderBy = (string) ($args['order_by'] ?? (in_array('id', $columns, true) ? 'id' : $columns[0]));
        if (! in_array($orderBy, $columns, true)) {
            $orderBy = in_array('id', $columns, true) ? 'id' : $columns[0];
        }
        $direction = strtolower((string) ($args['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $query = DB::table('sheets');

        if (! empty($args['search']) && $searchable !== []) {
            $keyword = (string) $args['search'];
            $query->where(function ($q) use ($searchable, $keyword): void {
                foreach ($searchable as $idx => $column) {
                    if ($idx === 0) {
                        $q->where($column, 'like', '%'.$keyword.'%');
                    } else {
                        $q->orWhere($column, 'like', '%'.$keyword.'%');
                    }
                }
            });
        }

        $paginated = $query->orderBy($orderBy, $direction)->paginate($perPage, ['*'], 'page', $page);

        return $response->text(json_encode([
            'type' => 'list_sheet_records',
            'table' => 'sheets',
            'total' => $paginated->total(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            'per_page' => $paginated->perPage(),
            'rows' => collect($paginated->items())->map(fn ($row) => (array) $row)->values()->all(),
        ]));
    }
}
