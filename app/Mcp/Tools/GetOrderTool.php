<?php

namespace App\Mcp\Tools;

use App\Models\SheetOrder;
use Illuminate\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetOrderTool extends Tool
{
    protected string $description = 'Get a single order by its id (integer) or order_no (string).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id'       => $schema->integer()->description('Order primary key')->nullable(),
            'order_no' => $schema->string()->description('Order number')->nullable(),
        ];
    }

    public function handle(Request $request, Response $response): Response
    {
        $args = $request->arguments();

        $order = isset($args['id'])
            ? SheetOrder::find($args['id'])
            : SheetOrder::where('order_no', $args['order_no'] ?? '')->first();

        if (!$order) {
            return $response->error('Order not found.');
        }

        return $response->text(json_encode([
            'type'  => 'order_detail',
            'order' => $order->toArray(),
        ]));
    }
}