<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetRequisitionRecordTool extends Tool
{
    protected string $description = 'Get a single row from requisitions by key.';

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
        'requisition_number',
        'category_id',
        'user_id',
        'title',
        'description',
        'total_amount',
        'status',
        'requisition_date',
        'daily_budget_id',
        'approved_at',
        'paid_at',
        'approved_by',
        'created_at',
        'updated_at'
    ];
        $key = (string) ($args['primary_key'] ?? 'id');
        if (! in_array($key, $columns, true)) {
            return $response->error('primary_key is not a valid column.');
        }

        $row = DB::table('requisitions')
            ->where($key, (string) $args['primary_value'])
            ->first();

        if (! $row) {
            return $response->error('Record not found.');
        }

        return $response->text(json_encode([
            'type' => 'get_requisition_record',
            'table' => 'requisitions',
            'record' => (array) $row,
        ]));
    }
}
