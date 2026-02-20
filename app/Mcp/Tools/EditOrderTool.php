<?php

namespace App\Mcp\Tools;

use App\Models\SheetOrder;
use Illuminate\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class EditOrderTool extends Tool
{
    protected string $description = 'Edit an existing order. Provide id OR order_no to identify it, plus only the fields to change.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id'            => $schema->integer()->description('Order primary key')->nullable(),
            'order_no'      => $schema->string()->description('Order number')->nullable(),
            'amount'        => $schema->number()->nullable(),
            'quantity'      => $schema->integer()->nullable(),
            'status'        => $schema->string()->nullable(),
            'delivery_date' => $schema->string()->nullable(),
            'client_name'   => $schema->string()->nullable(),
            'product_name'  => $schema->string()->nullable(),
            'city'          => $schema->string()->nullable(),
            'country'       => $schema->string()->nullable(),
            'phone'         => $schema->string()->nullable(),
            'agent'         => $schema->string()->nullable(),
            'store_name'    => $schema->string()->nullable(),
            'confirmed'     => $schema->boolean()->nullable(),
            'comments'      => $schema->string()->nullable(),
            'instructions'  => $schema->string()->nullable(),
            'address'       => $schema->string()->nullable(),
        ];
    }

    public function handle(Request $request, Response $response): Response
    {
        $args = $request->arguments();

        if (empty($args['id']) && empty($args['order_no'])) {
            return $response->error('Provide either id or order_no to identify the order.');
        }

        $order = isset($args['id'])
            ? SheetOrder::find($args['id'])
            : SheetOrder::where('order_no', $args['order_no'])->first();

        if (!$order) {
            return $response->error('Order not found.');
        }

        $order->update(collect($args)->except(['id', 'order_no'])->toArray());

        return $response->text(json_encode([
            'type'    => 'order_updated',
            'message' => "Order #{$order->order_no} updated successfully.",
            'order'   => $order->fresh()->toArray(),
        ]));
    }
}