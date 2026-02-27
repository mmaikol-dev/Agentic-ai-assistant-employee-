<?php

namespace App\Mcp\Tools;

use App\Models\SheetOrder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class UserManagementTool extends Tool
{
    protected string $description = 'Create new users or edit existing users. For creating, provide user details without an id. For editing, provide the user id or email along with the fields to update.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Order ID')->nullable(),
            'order_no' => $schema->string()->description('Order number')->nullable(),
            'updates' => $schema->object()->description('Key-value fields to update')->nullable(),
        ];
    }

    public function handle(Request $request, Response $response): Response
    {
        $args = $request->arguments();

        $validator = Validator::make($args, [
            'id' => ['nullable', 'integer'],
            'order_no' => ['nullable', 'string'],
            'updates' => ['required', 'array'],
        ]);

        if ($validator->fails()) {
            return $response->error($validator->errors()->first());
        }

        if (empty($args['id']) && empty($args['order_no'])) {
            return $response->error('Provide id or order_no to identify the record.');
        }

        $order = isset($args['id'])
            ? SheetOrder::find((int) $args['id'])
            : SheetOrder::where('order_no', $args['order_no'])->first();

        if (! $order) {
            return $response->error('Order not found.');
        }

        $order->update((array) ($args['updates'] ?? []));

        return $response->text(json_encode([
            'type' => 'user_management',
            'message' => 'UserManagementTool executed successfully.',
            'order' => $order->fresh()->toArray(),
        ]));
    }
}
