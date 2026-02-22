<?php

namespace App\Mcp\Tools;

use App\Models\SheetOrder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListOrdersTool extends Tool
{
    protected string $description = 'List orders from sheet_orders. Filters: status, client_name, merchant, phone, code, alt_no, agent, city, country, search. Supports page and per_page.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'page'        => $schema->integer()->description('Page number, default 1')->nullable(),
            'per_page'    => $schema->integer()->description('Results per page, max 50, default 15')->nullable(),
            'status'      => $schema->string()->description('Filter by order status')->nullable(),
            'client_name' => $schema->string()->description('Filter by client name (partial match)')->nullable(),
            'merchant'    => $schema->string()->description('Filter by merchant (partial match)')->nullable(),
            'phone'       => $schema->string()->description('Filter by phone (partial match)')->nullable(),
            'code'        => $schema->string()->description('Filter by code (partial match)')->nullable(),
            'code_is_empty' => $schema->boolean()->description('Set true to return rows where code is null/empty; false for non-empty code')->nullable(),
            'alt_no'      => $schema->string()->description('Filter by alternative number (partial match)')->nullable(),
            'agent'       => $schema->string()->description('Filter by agent')->nullable(),
            'city'        => $schema->string()->description('Filter by city')->nullable(),
            'country'     => $schema->string()->description('Filter by country')->nullable(),
            'search'      => $schema->string()->description('Search order_no, product_name, client_name, merchant, city, agent, phone, code, alt_no, store_name')->nullable(),
        ];
    }

    public function handle(Request $request, Response $response): Response
    {
        $args    = $request->arguments();
        $page    = max(1, (int) ($args['page'] ?? 1));
        $perPage = min(50, max(5, (int) ($args['per_page'] ?? 15)));

        $query = SheetOrder::query()->latest('order_date');

        if (!empty($args['status']))      $query->where('status', $args['status']);
        if (!empty($args['client_name'])) $query->where('client_name', 'like', '%'.$args['client_name'].'%');
        if (!empty($args['merchant']))    $query->where('merchant', 'like', '%'.$args['merchant'].'%');
        if (!empty($args['phone']))       $query->where('phone', 'like', '%'.$args['phone'].'%');
        if (!empty($args['code']))        $query->where('code', 'like', '%'.$args['code'].'%');
        if (array_key_exists('code_is_empty', $args)) {
            $codeIsEmpty = filter_var($args['code_is_empty'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($codeIsEmpty === true) {
                $query->where(fn($q) => $q->whereNull('code')->orWhere('code', ''));
            } elseif ($codeIsEmpty === false) {
                $query->whereNotNull('code')->where('code', '!=', '');
            }
        }
        if (!empty($args['alt_no']))      $query->where('alt_no', 'like', '%'.$args['alt_no'].'%');
        if (!empty($args['agent']))       $query->where('agent', $args['agent']);
        if (!empty($args['city']))        $query->where('city', 'like', '%'.$args['city'].'%');
        if (!empty($args['country']))     $query->where('country', $args['country']);
        if (!empty($args['search'])) {
            $s = $args['search'];
            $query->where(fn($q) => $q
                ->where('order_no', 'like', "%$s%")
                ->orWhere('product_name', 'like', "%$s%")
                ->orWhere('client_name', 'like', "%$s%")
                ->orWhere('merchant', 'like', "%$s%")
                ->orWhere('city', 'like', "%$s%")
                ->orWhere('agent', 'like', "%$s%")
                ->orWhere('phone', 'like', "%$s%")
                ->orWhere('code', 'like', "%$s%")
                ->orWhere('alt_no', 'like', "%$s%")
                ->orWhere('store_name', 'like', "%$s%")
            );
        }

        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

        $result = [
            'type'         => 'orders_table',
            'total'        => $paginated->total(),
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
            'per_page'     => $paginated->perPage(),
            'orders'       => collect($paginated->items())->map->toArray()->values()->all(),
        ];

        return $response->text(json_encode($result));
    }
}
