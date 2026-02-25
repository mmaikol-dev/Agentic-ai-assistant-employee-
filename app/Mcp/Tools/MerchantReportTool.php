<?php

namespace App\Mcp\Tools;

use App\Models\SheetOrder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class MerchantReportTool extends Tool
{
    protected string $description = 'Generate merchant-focused performance reports for a specific order status with optional filters.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->description('Required order status filter (for example delivered, scheduled, cancelled, pending)'),
            'merchant' => $schema->string()->description('Optional merchant name (partial match). If omitted, returns multi-merchant report.')->nullable(),
            'start_date' => $schema->string()->description('Start date (YYYY-MM-DD) using order_date')->nullable(),
            'end_date' => $schema->string()->description('End date (YYYY-MM-DD) using order_date')->nullable(),
            'country' => $schema->string()->description('Country filter')->nullable(),
            'city' => $schema->string()->description('City filter (partial match)')->nullable(),
            'agent' => $schema->string()->description('Agent filter (partial match)')->nullable(),
            'limit' => $schema->integer()->description('Max rows for merchant table, default 20, max 200')->nullable(),
        ];
    }

    public function handle(Request $request, Response $response): Response
    {
        $args = $request->arguments();

        $validator = Validator::make($args, [
            'status' => ['required', 'string'],
            'merchant' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'country' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'agent' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        if ($validator->fails()) {
            return $response->error($validator->errors()->first());
        }

        $status = strtolower(trim((string) ($args['status'] ?? '')));
        $query = SheetOrder::query()
            ->whereRaw('LOWER(TRIM(status)) = ?', [$status]);

        if (! empty($args['merchant'])) {
            $query->where('merchant', 'like', '%'.$args['merchant'].'%');
        }
        if (! empty($args['country'])) {
            $query->where('country', 'like', '%'.$args['country'].'%');
        }
        if (! empty($args['city'])) {
            $query->where('city', 'like', '%'.$args['city'].'%');
        }
        if (! empty($args['agent'])) {
            $query->where('agent', 'like', '%'.$args['agent'].'%');
        }
        if (! empty($args['start_date'])) {
            $query->whereDate('order_date', '>=', $args['start_date']);
        }
        if (! empty($args['end_date'])) {
            $query->whereDate('order_date', '<=', $args['end_date']);
        }

        $orders = $query->get([
            'merchant',
            'product_name',
            'quantity',
            'amount',
            'country',
            'city',
            'agent',
            'order_date',
            'status',
            'instructions',
        ]);

        if ($orders->isEmpty()) {
            return $response->text(json_encode([
                'type' => 'merchant_report',
                'empty' => true,
                'message' => "No '{$status}' orders matched the merchant report filters.",
                'filters' => $this->appliedFilters($args),
            ]));
        }

        $totalRevenue = $orders->sum(fn ($row) => $this->parseAmount($row->amount));
        $totalOrders = $orders->count();
        $totalQuantity = $orders->sum(fn ($row) => (float) ($row->quantity ?? 0));

        $limit = min(200, max(1, (int) ($args['limit'] ?? 20)));

        $byMerchant = $orders
            ->groupBy(fn ($o) => trim((string) ($o->merchant ?? 'Unknown')))
            ->map(function ($group, $merchant) use ($totalRevenue) {
                $merchantRevenue = $group->sum(fn ($row) => $this->parseAmount($row->amount));
                $merchantOrders = $group->count();
                $merchantQty = $group->sum(fn ($row) => (float) ($row->quantity ?? 0));

                return [
                    'merchant' => $merchant,
                    'order_count' => $merchantOrders,
                    'total_quantity' => round($merchantQty, 2),
                    'total_revenue' => round($merchantRevenue, 2),
                    'average_order_value' => $merchantOrders > 0 ? round($merchantRevenue / $merchantOrders, 2) : 0.0,
                    'revenue_share_pct' => $totalRevenue > 0 ? round(($merchantRevenue / $totalRevenue) * 100, 2) : 0.0,
                ];
            })
            ->sortByDesc('total_revenue')
            ->take($limit)
            ->values()
            ->all();

        $topProducts = $orders
            ->groupBy(fn ($o) => trim((string) ($o->product_name ?? 'Unknown')))
            ->map(function ($group, $product) {
                $revenue = $group->sum(fn ($row) => $this->parseAmount($row->amount));
                $count = $group->count();
                $qty = $group->sum(fn ($row) => (float) ($row->quantity ?? 0));

                return [
                    'product_name' => $product,
                    'order_count' => $count,
                    'total_quantity' => round($qty, 2),
                    'total_revenue' => round($revenue, 2),
                ];
            })
            ->sortByDesc('total_revenue')
            ->take(10)
            ->values()
            ->all();

        $statusBreakdown = $orders
            ->groupBy(function ($o): string {
                $status = strtolower(trim((string) ($o->status ?? 'unknown')));
                return $status !== '' ? $status : 'unknown';
            })
            ->map(fn ($group, $status) => [
                'status' => $status,
                'order_count' => $group->count(),
            ])
            ->sortByDesc('order_count')
            ->values()
            ->all();

        $instructions = $orders
            ->map(fn ($o): string => trim((string) ($o->instructions ?? '')))
            ->values();

        $instructionsProvidedCount = $instructions
            ->filter(fn (string $value): bool => $value !== '')
            ->count();
        $instructionsMissingCount = $totalOrders - $instructionsProvidedCount;

        $topInstructions = $instructions
            ->filter(fn (string $value): bool => $value !== '')
            ->map(function (string $value): string {
                $normalized = preg_replace('/\s+/', ' ', $value);
                $normalized = is_string($normalized) ? trim($normalized) : $value;

                return mb_substr($normalized, 0, 120);
            })
            ->countBy()
            ->sortDesc()
            ->take(10)
            ->map(fn ($count, $instruction) => [
                'instruction' => (string) $instruction,
                'count' => (int) $count,
            ])
            ->values()
            ->all();

        $topMerchant = $byMerchant[0] ?? null;

        return $response->text(json_encode([
            'type' => 'merchant_report',
            'empty' => false,
            'filters' => $this->appliedFilters($args),
            'summary' => [
                'total_orders' => $totalOrders,
                'total_quantity' => round($totalQuantity, 2),
                'total_revenue' => round($totalRevenue, 2),
                'merchant_count' => count($byMerchant),
                'top_merchant' => $topMerchant['merchant'] ?? null,
                'top_merchant_revenue' => $topMerchant['total_revenue'] ?? null,
            ],
            'by_merchant' => $byMerchant,
            'top_products' => $topProducts,
            'status_breakdown' => $statusBreakdown,
            'instructions_analysis' => [
                'orders_with_instructions' => $instructionsProvidedCount,
                'orders_without_instructions' => $instructionsMissingCount,
                'instructions_coverage_pct' => $totalOrders > 0
                    ? round(($instructionsProvidedCount / $totalOrders) * 100, 2)
                    : 0.0,
                'top_instructions' => $topInstructions,
            ],
        ]));
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function appliedFilters(array $args): array
    {
        return array_filter([
            'status' => $args['status'] ?? null,
            'merchant' => $args['merchant'] ?? null,
            'start_date' => $args['start_date'] ?? null,
            'end_date' => $args['end_date'] ?? null,
            'country' => $args['country'] ?? null,
            'city' => $args['city'] ?? null,
            'agent' => $args['agent'] ?? null,
        ], fn ($value): bool => $value !== null && $value !== '');
    }

    private function parseAmount(mixed $raw): float
    {
        if (is_numeric($raw)) {
            return (float) $raw;
        }

        return (float) preg_replace('/[^0-9.\-]/', '', (string) $raw);
    }
}
