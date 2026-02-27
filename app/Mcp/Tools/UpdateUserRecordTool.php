<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class UpdateUserRecordTool extends Tool
{
    protected string $description = 'Update a row in users by key.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'primary_key' => $schema->string()->description('Key column, default id')->nullable(),
            'primary_value' => $schema->string()->description('Key value to update'),
            'updates' => $schema->object()->description('Object of column => value pairs to update'),
        ];
    }

    public function handle(Request $request, Response $response): Response
    {
        $args = $request->arguments();

        $validator = Validator::make($args, [
            'primary_key' => ['nullable', 'string'],
            'primary_value' => ['required', 'string'],
            'updates' => ['required', 'array'],
        ]);

        if ($validator->fails()) {
            return $response->error($validator->errors()->first());
        }

        $key = (string) ($args['primary_key'] ?? 'id');
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
        if (! in_array($key, array_merge($writable, ['id', 'id']), true)) {
            return $response->error('primary_key is not valid for this table.');
        }

        $updates = collect((array) $args['updates'])
            ->only($writable)
            ->all();

        if ($updates === []) {
            return $response->error('No writable update columns were provided.');
        }

        try {
            $affected = DB::table('users')
                ->where($key, (string) $args['primary_value'])
                ->update($updates);
        } catch (\Throwable $e) {
            return $response->error('Update failed: '.$e->getMessage());
        }

        if ($affected < 1) {
            return $response->error('No rows were updated.');
        }

        $record = DB::table('users')
            ->where($key, (string) $args['primary_value'])
            ->first();

        return $response->text(json_encode([
            'type' => 'update_user_record',
            'table' => 'users',
            'updated_rows' => $affected,
            'record' => $record ? (array) $record : null,
        ]));
    }
}
