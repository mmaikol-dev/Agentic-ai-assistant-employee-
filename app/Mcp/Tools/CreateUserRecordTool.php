<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CreateUserRecordTool extends Tool
{
    protected string $description = 'Create a row in users.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'data' => $schema->object()->description('Object of column => value pairs to insert'),
        ];
    }

    public function handle(Request $request, Response $response): Response
    {
        $args = $request->arguments();

        $validator = Validator::make($args, [
            'data' => ['required', 'array'],
        ]);

        if ($validator->fails()) {
            return $response->error($validator->errors()->first());
        }

        $writable = [
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
        'photo',
        'roles',
        'status'
    ];
        $data = collect((array) $args['data'])
            ->only($writable)
            ->all();

        if ($data === []) {
            return $response->error('No writable columns were provided.');
        }

        try {
            $id = DB::table('users')->insertGetId($data);
            $record = DB::table('users')->where('id', $id)->first();
        } catch (\Throwable $e) {
            return $response->error('Insert failed: '.$e->getMessage());
        }

        return $response->text(json_encode([
            'type' => 'create_user_record',
            'table' => 'users',
            'id' => $id,
            'record' => $record ? (array) $record : null,
        ]));
    }
}
