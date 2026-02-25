<?php

namespace App\Mcp\Tools;

use App\Models\SheetOrder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class FinancialReportTool extends Tool
{
    protected string $description = 'Generates a financial report for delivered orders where remittance is tracked in agent (remitted/remittted), grouped by merchant, including total revenue, order count, and average order value. All filters are optional.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'merchant'   => $schema->string()->description('Filter by merchant name (partial match)')->nullable(),
            'start_date' => $schema->string()->description('Start date for the report (ISO format: YYYY-MM-DD)')->nullable(),
            'end_date'   => $schema->string()->description('End date for the report (ISO format: YYYY-MM-DD)')->nullable(),
            'country'    => $schema->string()->description('Filter by country')->nullable(),
            'city'       => $schema->string()->description('Filter by city')->nullable(),
            'agent'      => $schema->string()->description('Filter by agent name (optional override)')->nullable(),
        ];
    }

    public function handle(Request $request, Response $response): Response
    {
        $args = $request->arguments();

        $validator = Validator::make($args, [
            'merchant'   => ['nullable', 'string'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date'   => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'country'    => ['nullable', 'string'],
            'city'       => ['nullable', 'string'],
            'agent'      => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $response->error($validator->errors()->first());
        }

        // Base query: delivered orders with remittance tracked in agent column.
        $query = SheetOrder::query()
            ->whereRaw('LOWER(TRIM(status)) = ?', ['delivered']);

        if (! empty($args['agent'])) {
            $query->where('agent', 'like', '%' . $args['agent'] . '%');
        } else {
            $query->whereRaw('LOWER(TRIM(agent)) in (?, ?)', ['remitted', 'remittted']);
        }
        if (!empty($args['merchant'])) {
            $query->where('merchant', 'like', '%' . $args['merchant'] . '%');
        }
        if (!empty($args['start_date'])) {
            $query->whereDate('order_date', '>=', $args['start_date']);
        }
        if (!empty($args['end_date'])) {
            $query->whereDate('order_date', '<=', $args['end_date']);
        }
        if (!empty($args['country'])) {
            $query->where('country', 'like', '%' . $args['country'] . '%');
        }
        if (!empty($args['city'])) {
            $query->where('city', 'like', '%' . $args['city'] . '%');
        }

        $orders = $query->get(['merchant', 'amount', 'quantity', 'country', 'city', 'agent', 'order_date', 'status']);

        if ($orders->isEmpty()) {
            return $response->text(json_encode([
                'type'    => 'financial_report',
                'empty'   => true,
                'message' => 'No delivered orders with remitted/remittted agent matched the specified filters.',
                'filters' => $this->appliedFilters($args),
            ]));
        }

        $totalRevenue = $orders->sum('amount');
        $totalOrders  = $orders->count();
        $averageOV    = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;

        // Group by merchant
        $byMerchant = $orders
            ->groupBy(fn($o) => $o->merchant ?? 'Unknown')
            ->map(function ($group, $name) use ($totalRevenue) {
                $revenue  = $group->sum('amount');
                $count    = $group->count();
                return [
                    'merchant'            => $name,
                    'order_count'         => $count,
                    'total_revenue'       => round($revenue, 2),
                    'average_order_value' => $count > 0 ? round($revenue / $count, 2) : 0,
                    'revenue_share_pct'   => $totalRevenue > 0 ? round(($revenue / $totalRevenue) * 100, 1) : 0,
                ];
            })
            ->sortByDesc('total_revenue')
            ->values()
            ->toArray();

        // Group by country
        $byCountry = $orders
            ->groupBy(fn($o) => $o->country ?? 'Unknown')
            ->map(fn($group, $country) => [
                'country'       => $country,
                'order_count'   => $group->count(),
                'total_revenue' => round($group->sum('amount'), 2),
            ])
            ->sortByDesc('total_revenue')
            ->values()
            ->toArray();

        // Group by city (top 10)
        $byCity = $orders
            ->groupBy(fn($o) => $o->city ?? 'Unknown')
            ->map(fn($group, $city) => [
                'city'          => $city,
                'order_count'   => $group->count(),
                'total_revenue' => round($group->sum('amount'), 2),
            ])
            ->sortByDesc('total_revenue')
            ->take(10)
            ->values()
            ->toArray();

        $topMerchant = $byMerchant[0] ?? null;

        return $response->text(json_encode([
            'type'    => 'financial_report',
            'empty'   => false,
            'filters' => $this->appliedFilters($args),
            'summary' => [
                'total_orders'         => $totalOrders,
                'total_revenue'        => round($totalRevenue, 2),
                'average_order_value'  => round($averageOV, 2),
                'merchant_count'       => count($byMerchant),
                'top_merchant'         => $topMerchant['merchant'] ?? null,
                'top_merchant_revenue' => $topMerchant['total_revenue'] ?? null,
            ],
            'by_merchant' => $byMerchant,
            'by_country'  => $byCountry,
            'by_city'     => $byCity,
        ]));
    }

    private function appliedFilters(array $args): array
    {
        return array_filter([
            'merchant'   => $args['merchant']   ?? null,
            'start_date' => $args['start_date'] ?? null,
            'end_date'   => $args['end_date']   ?? null,
            'country'    => $args['country']    ?? null,
            'city'       => $args['city']       ?? null,
            'agent'      => $args['agent']      ?? null,
        ]);
    }
}
