<?php

namespace App\Mcp\Tools;

use App\Models\SheetOrder;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CallCenterMonthlyReportTool extends Tool
{
    protected string $description = 'Generate a monthly call center report showing total orders for a requested month filtered by call center agent (cc_email), with summary counts grouped by status.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'month' => $schema->string()->description('Target month (YYYY-MM). Required unless start_date/end_date are provided.')->nullable(),
            'cc_email' => $schema->string()->description('Call center agent email filter (exact, case-insensitive)')->nullable(),
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
            'month' => ['nullable', 'date_format:Y-m'],
            'cc_email' => ['nullable', 'string'],
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

        if (empty($args['month']) && empty($args['start_date']) && empty($args['end_date'])) {
            return $response->error('Provide month (YYYY-MM) or a start_date/end_date range.');
        }

        if (! empty($args['start_date']) && ! empty($args['end_date']) && $args['start_date'] > $args['end_date']) {
            return $response->error('start_date must be before or equal to end_date.');
        }

        $query = SheetOrder::query();

        if (! empty($args['month'])) {
            $month = Carbon::createFromFormat('Y-m', (string) $args['month']);
            $query->whereDate('order_date', '>=', $month->copy()->startOfMonth()->toDateString())
                ->whereDate('order_date', '<=', $month->copy()->endOfMonth()->toDateString());
        }

        if (! empty($args['merchant'])) {
            $query->where('merchant', 'like', '%'.$args['merchant'].'%');
        }
        if (! empty($args['cc_email'])) {
            $query->whereRaw('LOWER(TRIM(cc_email)) = ?', [strtolower(trim((string) $args['cc_email']))]);
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
            return $response->error('No orders found for the provided monthly filters.');
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

        $statusBreakdown = $orders
            ->groupBy(fn ($o) => strtolower(trim((string) ($o->status ?? 'unknown'))))
            ->map(fn ($group, $status) => ['status' => $status === '' ? 'unknown' : $status, 'order_count' => $group->count()])
            ->sortByDesc('order_count')
            ->values()
            ->all();

        $limit = min(200, max(1, (int) ($args['limit'] ?? 50)));
        $listedOrders = $orders
            ->take($limit)
            ->map(fn ($o) => [
                'order_no' => $o->order_no,
                'client_name' => $o->client_name,
                'status' => $o->status,
                'code' => $o->code,
                'city' => $o->city,
                'cc_email' => $o->cc_email,
            ])
            ->values()
            ->all();

        return $response->text(json_encode([
            'type' => 'call_center_monthly_report',
            'merchant' => $args['merchant'] ?? null,
            'filters' => [
                'month' => $args['month'] ?? null,
                'cc_email' => $args['cc_email'] ?? null,
                'country' => $args['country'] ?? null,
                'city' => $args['city'] ?? null,
                'start_date' => $args['start_date'] ?? null,
                'end_date' => $args['end_date'] ?? null,
            ],
            'total_orders' => $orderCount,
            'total_revenue' => round($totalRevenue, 2),
            'average_order_value' => round($avg, 2),
            'status_breakdown' => $statusBreakdown,
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
