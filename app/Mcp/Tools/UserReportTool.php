<?php

namespace App\Mcp\Tools;

use App\Models\SheetOrder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class UserReportTool extends Tool
{
    protected string $description = 'Generates downloadable Excel reports for users based on filter criteria. Supports single or multiple users.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'merchant' => $schema->string()->description('Merchant filter (partial match)')->nullable(),
            'start_date' => $schema->string()->description('Start date (YYYY-MM-DD)')->nullable(),
            'end_date' => $schema->string()->description('End date (YYYY-MM-DD)')->nullable(),
            'country' => $schema->string()->description('Country filter')->nullable(),
            'city' => $schema->string()->description('City filter (partial match)')->nullable(),
            'limit' => $schema->integer()->description('Rows to include in listing section, max 200, default 50')->nullable(),
        ];
    }

    public function handle(Request $request, Response $response): Response
    {
        $args = $request->arguments();

        $validator = Validator::make($args, [
            'merchant' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'country' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        if ($validator->fails()) {
            return $response->error($validator->errors()->first());
        }

        $query = SheetOrder::query()->where('status', 'Delivered');

        if (! empty($args['merchant'])) {
            $query->where('merchant', 'like', '%'.$args['merchant'].'%');
        }
        if (! empty($args['country'])) {
            $query->where('country', $args['country']);
        }
        if (! empty($args['city'])) {
            $query->where('city', 'like', '%'.$args['city'].'%');
        }
        if (! empty($args['start_date'])) {
            $query->whereDate('order_date', '>=', $args['start_date']);
        }
        if (! empty($args['end_date'])) {
            $query->whereDate('order_date', '<=', $args['end_date']);
        }

        $orders = $query->orderByDesc('order_date')->get();

        if ($orders->isEmpty()) {
            return $response->error('No delivered orders found for the provided filters.');
        }

        $amounts = $orders->map(function ($order) {
            $raw = $order->amount;
            if (is_numeric($raw)) {
                return (float) $raw;
            }
            return (float) preg_replace('/[^0-9.\-]/', '', (string) $raw);
        });

        $totalRevenue = $amounts->sum();
        $orderCount = $orders->count();
        $avg = $orderCount > 0 ? $totalRevenue / $orderCount : 0.0;

        $productBreakdown = $orders
            ->groupBy(fn ($o) => trim((string) ($o->product_name ?? 'Unknown')))
            ->map(function ($group, $product) {
                $revenue = $group->sum(function ($order) {
                    $raw = $order->amount;
                    if (is_numeric($raw)) {
                        return (float) $raw;
                    }
                    return (float) preg_replace('/[^0-9.\-]/', '', (string) $raw);
                });
                $count = $group->count();

                return [
                    'product_name' => $product,
                    'order_count' => $count,
                    'total_revenue' => round($revenue, 2),
                    'average_price' => round($count > 0 ? $revenue / $count : 0.0, 2),
                ];
            })
            ->sortByDesc('order_count')
            ->values()
            ->take(8)
            ->all();

        $cityBreakdown = $orders
            ->groupBy(fn ($o) => trim((string) ($o->city ?? 'Unknown')))
            ->map(fn ($group, $city) => ['city' => $city, 'order_count' => $group->count()])
            ->sortByDesc('order_count')
            ->values()
            ->take(8)
            ->all();

        $limit = min(200, max(1, (int) ($args['limit'] ?? 50)));
        $listedOrders = $orders->take($limit)->map(fn ($o) => $o->toArray())->values()->all();

        return $response->text(json_encode([
            'type' => 'user_report',
            'merchant' => $args['merchant'] ?? null,
            'status' => 'Delivered',
            'filters' => [
                'country' => $args['country'] ?? null,
                'city' => $args['city'] ?? null,
                'start_date' => $args['start_date'] ?? null,
                'end_date' => $args['end_date'] ?? null,
            ],
            'total_orders' => $orderCount,
            'total_revenue' => round($totalRevenue, 2),
            'average_order_value' => round($avg, 2),
            'product_breakdown' => $productBreakdown,
            'city_breakdown' => $cityBreakdown,
            'listed_orders_count' => count($listedOrders),
            'orders' => $listedOrders,
        ]));
    }
}
