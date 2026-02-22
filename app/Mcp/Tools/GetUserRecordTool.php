<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetUserRecordTool extends Tool
{
    protected string $description = 'Get a single row from users by key.';

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
        'username',
        'name',
        'email',
        'email_verified_at',
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'store_name',
        'store_address',
        'store_phone',
        'store_email',
        'remember_token',
        'created_at',
        'updated_at',
        'photo',
        'roles',
        'status'
    ];
        $key = (string) ($args['primary_key'] ?? 'id');
        if (! in_array($key, $columns, true)) {
            return $response->error('primary_key is not a valid column.');
        }

        $row = DB::table('users')
            ->where($key, (string) $args['primary_value'])
            ->first();

        if (! $row) {
            return $response->error('Record not found.');
        }

        return $response->text(json_encode([
            'type' => 'get_user_record',
            'table' => 'users',
            'record' => (array) $row,
        ]));
    }
}
