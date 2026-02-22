<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetSheetRecordTool extends Tool
{
    protected string $description = 'Get a single row from sheets by key.';

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
        $key = (string) ($args['primary_key'] ?? 'id');
        if (! in_array($key, $columns, true)) {
            return $response->error('primary_key is not a valid column.');
        }

        $row = DB::table('sheets')
            ->where($key, (string) $args['primary_value'])
            ->first();

        if (! $row) {
            return $response->error('Record not found.');
        }

        return $response->text(json_encode([
            'type' => 'get_sheet_record',
            'table' => 'sheets',
            'record' => (array) $row,
        ]));
    }
}
