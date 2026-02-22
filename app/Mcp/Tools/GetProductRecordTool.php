<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetProductRecordTool extends Tool
{
    protected string $description = 'Get a single row from products by key.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'primary_key' => $schema->string()->description('Key column, default id')->nullable(),
            'primary_value' => $schema->string()->description('Key value to look up'),
        ];
    }

    public function handle(Request $request, Response $response): Response
    {
        $args = $request->arguments();

        $validator = Validator::make($args, [
            'primary_key' => ['nullable', 'string'],
            'primary_value' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return $response->error($validator->errors()->first());
        }

        $columns = [
        'id',
        'company_id',
        'uuid',
        'user_id',
        'name',
        'store_name',
        'slug',
        'code',
        'quantity',
        'buying_price',
        'selling_price',
        'quantity_alert',
        'tax',
        'tax_type',
        'notes',
        'product_image',
        'category_id',
        'unit_id',
        'created_at',
        'updated_at',
        'movement_score'
    ];
        $key = (string) ($args['primary_key'] ?? 'id');
        if (! in_array($key, $columns, true)) {
            return $response->error('primary_key is not a valid column.');
        }

        $row = DB::table('products')
            ->where($key, (string) $args['primary_value'])
            ->first();

        if (! $row) {
            return $response->error('Record not found.');
        }

        return $response->text(json_encode([
            'type' => 'get_product_record',
            'table' => 'products',
            'record' => (array) $row,
        ]));
    }
}
