<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class UpdateRequisitionRecordTool extends Tool
{
    protected string $description = 'Update a row in requisitions by key.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'primary_key' => $schema->string()->description('Key column, default id')->nullable(),
            'primary_value' => $schema->string()->description('Key value to update'),
            'updates' => $schema->object()->description('Object of column => value pairs to update'),
            'company_name' => $schema->string()->description('Label for company_id from companies (name).')->nullable(),
            'category_name' => $schema->string()->description('Label for category_id from categories (name).')->nullable(),
            'user_name' => $schema->string()->description('Label for user_id from users (name).')->nullable(),
            'daily_budget_name' => $schema->string()->description('Label for daily_budget_id from daily_budgets (id).')->nullable(),
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
        'approved_by'
    ];
        if (! in_array($key, array_merge($writable, ['id', 'id']), true)) {
            return $response->error('primary_key is not valid for this table.');
        }

        $foreignAliasKeys = [
        'company_name',
        'category_name',
        'user_name',
        'daily_budget_name'
    ];
        $updates = collect((array) $args['updates'])
            ->only(array_merge($writable, $foreignAliasKeys))
            ->all();

        if ($updates === []) {
            return $response->error('No writable update columns were provided.');
        }

        [$updates, $selectionRequired, $selection] = $this->resolveForeignKeyInputs($updates);
        if ($selectionRequired) {
            return $response->text(json_encode([
                'type' => 'foreign_key_selection_required',
                'table' => 'requisitions',
                'message' => 'Provide a valid related value selection before update.',
                'selection' => $selection,
            ]));
        }

        try {
            $affected = DB::table('requisitions')
                ->where($key, (string) $args['primary_value'])
                ->update($updates);
        } catch (\Throwable $e) {
            return $response->error('Update failed: '.$e->getMessage());
        }

        if ($affected < 1) {
            return $response->error('No rows were updated.');
        }

        $record = DB::table('requisitions')
            ->where($key, (string) $args['primary_value'])
            ->first();

        return $response->text(json_encode([
            'type' => 'update_requisition_record',
            'table' => 'requisitions',
            'updated_rows' => $affected,
            'record' => $record ? (array) $record : null,
        ]));
    }

    /**
     * @var array<string, array<string, string>>
     */
    private array $foreignKeyHints = array (
  'company_id' => 
  array (
    'table' => 'companies',
    'id_column' => 'id',
    'display_column' => 'name',
    'input_alias' => 'company_name',
  ),
  'category_id' => 
  array (
    'table' => 'categories',
    'id_column' => 'id',
    'display_column' => 'name',
    'input_alias' => 'category_name',
  ),
  'user_id' => 
  array (
    'table' => 'users',
    'id_column' => 'id',
    'display_column' => 'name',
    'input_alias' => 'user_name',
  ),
  'daily_budget_id' => 
  array (
    'table' => 'daily_budgets',
    'id_column' => 'id',
    'display_column' => 'id',
    'input_alias' => 'daily_budget_name',
  ),
);

    /**
     * @param array<string, mixed> $payload
     * @return array{0: array<string, mixed>, 1: bool, 2: array<string, mixed>|null}
     */
    private function resolveForeignKeyInputs(array $payload): array
    {
        foreach ($this->foreignKeyHints as $foreignKey => $meta) {
            $alias = (string) ($meta['input_alias'] ?? '');
            if ($alias === '') {
                continue;
            }

            if (array_key_exists($foreignKey, $payload) && $payload[$foreignKey] !== null && $payload[$foreignKey] !== '') {
                unset($payload[$alias]);
                continue;
            }

            if (! array_key_exists($alias, $payload)) {
                continue;
            }

            $label = trim((string) $payload[$alias]);
            unset($payload[$alias]);
            if ($label === '') {
                continue;
            }

            $lookup = $this->lookupForeignKeyByLabel($meta, $label);
            if ((string) ($lookup['status'] ?? '') === 'ok') {
                $payload[$foreignKey] = $lookup['id'];
                continue;
            }

            return [$payload, true, [
                'foreign_key' => $foreignKey,
                'input_alias' => $alias,
                'query' => $label,
                'table' => $meta['table'] ?? null,
                'display_column' => $meta['display_column'] ?? 'name',
                'message' => $lookup['message'] ?? 'Invalid related value.',
                'choices' => $lookup['choices'] ?? [],
            ]];
        }

        return [$payload, false, null];
    }

    /**
     * @param array<string, string> $meta
     * @return array<string, mixed>
     */
    private function lookupForeignKeyByLabel(array $meta, string $label): array
    {
        $table = (string) ($meta['table'] ?? '');
        $display = (string) ($meta['display_column'] ?? 'name');
        $idColumn = (string) ($meta['id_column'] ?? 'id');

        if ($table === '') {
            return ['status' => 'error', 'message' => 'Related table is not configured.', 'choices' => []];
        }

        try {
            $exact = DB::table($table)
                ->select([$idColumn, $display])
                ->whereRaw('LOWER('.$display.') = ?', [mb_strtolower($label)])
                ->limit(2)
                ->get();

            if ($exact->count() === 1) {
                return ['status' => 'ok', 'id' => $exact->first()->{$idColumn}];
            }

            $choices = DB::table($table)
                ->select([$idColumn, $display])
                ->where($display, 'like', '%'.$label.'%')
                ->orderBy($display)
                ->limit(15)
                ->get()
                ->map(fn ($row): array => [
                    'id' => $row->{$idColumn},
                    'label' => $row->{$display},
                ])
                ->values()
                ->all();

            $message = $choices === []
                ? 'No related records matched this label.'
                : 'Multiple or partial matches found. Choose one of the listed options.';

            return ['status' => 'error', 'message' => $message, 'choices' => $choices];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'Foreign lookup failed: '.$e->getMessage(), 'choices' => []];
        }
    }
}
