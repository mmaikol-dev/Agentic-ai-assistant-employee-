<?php

namespace App\Mcp\Tools;

use App\Models\SheetOrder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CallCenterDailyReportTool extends Tool
{
    protected string $description = 'Generate a daily call center report using delivery_date for the current day by default, showing orders with status \'scheduled\' or \'delivered\' and code not null. Lists only order_no and mpesa_code.';

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

        $query = SheetOrder::query()
            ->where(function ($q): void {
                $q->whereRaw('LOWER(TRIM(status)) = ?', ['scheduled'])
                    ->orWhereRaw('LOWER(TRIM(status)) = ?', ['delivered']);
            })
            ->whereNotNull('code');

        if (! empty($args['merchant'])) {
            $query->where('merchant', 'like', '%'.$args['merchant'].'%');
        }
        if (! empty($args['country'])) {
            $query->where('country', $args['country']);
        }
        if (! empty($args['city'])) {
            $query->where('city', 'like', '%'.$args['city'].'%');
        }

        if (! empty($args['start_date']) && ! empty($args['end_date']) && $args['start_date'] > $args['end_date']) {
            return $response->error('start_date must be before or equal to end_date.');
        }

        $startDate = (string) ($args['start_date'] ?? now()->toDateString());
        $endDate = (string) ($args['end_date'] ?? $startDate);

        $query->whereDate('delivery_date', '>=', $startDate)
            ->whereDate('delivery_date', '<=', $endDate);

        $orders = $query->orderByDesc('delivery_date')->get();

        if ($orders->isEmpty()) {
            return $response->error('No scheduled or delivered orders with code found for the provided filters.');
        }

        $amounts = $orders->map(fn ($order) => $this->parseAmount($order->amount));

        $totalRevenue = $amounts->sum();
        $orderCount = $orders->count();
        $avg = $orderCount > 0 ? $totalRevenue / $orderCount : 0.0;

        $productBreakdown = $orders
            ->groupBy(fn ($o) => trim((string) ($o->product_name ?? 'Unknown')))
            ->map(function ($group, $product) {
                $revenue = $group->sum(fn ($order) => $this->parseAmount($order->amount));
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
        $listedOrders = $orders
            ->take($limit)
            ->map(fn ($o) => [
                'order_no' => $o->order_no,
                'mpesa_code' => $o->code,
            ])
            ->values()
            ->all();

        return $response->text(json_encode([
            'type' => 'call_center_daily_report',
            'merchant' => $args['merchant'] ?? null,
            'statuses' => ['scheduled', 'delivered'],
            'filters' => [
                'country' => $args['country'] ?? null,
                'city' => $args['city'] ?? null,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'date_field' => 'delivery_date',
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

    private function parseAmount(mixed $raw): float
    {
        if (is_numeric($raw)) {
            return (float) $raw;
        }

        return (float) preg_replace('/[^0-9.\-]/', '', (string) $raw);
    }
}
