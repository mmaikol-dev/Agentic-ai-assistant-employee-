<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetBudgetTransactionRecordTool extends Tool
{
    protected string $description = 'Get a single row from budget_transactions by key.';

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
        'daily_budget_id',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'reference_type',
        'reference_id',
        'description',
        'created_by',
        'created_at',
        'updated_at'
    ];
        $key = (string) ($args['primary_key'] ?? 'id');
        if (! in_array($key, $columns, true)) {
            return $response->error('primary_key is not a valid column.');
        }

        $row = DB::table('budget_transactions')
            ->where($key, (string) $args['primary_value'])
            ->first();

        if (! $row) {
            return $response->error('Record not found.');
        }

        return $response->text(json_encode([
            'type' => 'get_budget_transaction_record',
            'table' => 'budget_transactions',
            'record' => (array) $row,
        ]));
    }
}
