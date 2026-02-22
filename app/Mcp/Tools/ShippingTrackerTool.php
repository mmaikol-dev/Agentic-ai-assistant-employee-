<?php

namespace App\Mcp\Tools;

use App\Models\SheetOrder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ShippingTrackerTool extends Tool
{
    protected string $description = 'Create shipments, track deliveries, and update order delivery status via logistics APIs';

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Search across order_no, client_name, product_name, merchant')->nullable(),
            'status' => $schema->string()->description('Status filter')->nullable(),
            'merchant' => $schema->string()->description('Merchant filter (partial match)')->nullable(),
            'page' => $schema->integer()->description('Page number, default 1')->nullable(),
            'per_page' => $schema->integer()->description('Results per page, max 100, default 20')->nullable(),
        ];
    }

    public function handle(Request $request, Response $response): Response
    {
        $args = $request->arguments();

        $validator = Validator::make($args, [
            'search' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'merchant' => ['nullable', 'string'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $response->error($validator->errors()->first());
        }

        $page = max(1, (int) ($args['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($args['per_page'] ?? 20)));
        $query = SheetOrder::query()->latest('order_date');

        if (! empty($args['status'])) {
            $query->where('status', $args['status']);
        }
        if (! empty($args['merchant'])) {
            $query->where('merchant', 'like', '%'.$args['merchant'].'%');
        }
        if (! empty($args['search'])) {
            $s = $args['search'];
            $query->where(fn ($q) => $q
                ->where('order_no', 'like', "%{$s}%")
                ->orWhere('client_name', 'like', "%{$s}%")
                ->orWhere('product_name', 'like', "%{$s}%")
                ->orWhere('merchant', 'like', "%{$s}%")
            );
        }

        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

        return $response->text(json_encode([
            'type' => 'shipping_tracker',
            'total' => $paginated->total(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            'per_page' => $paginated->perPage(),
            'rows' => collect($paginated->items())->map->toArray()->values()->all(),
        ]));
    }
}
