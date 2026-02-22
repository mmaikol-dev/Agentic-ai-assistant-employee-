<?php

namespace App\Services;

use App\Models\SheetOrder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ReportTaskService
{
    private string $dir;

    public function __construct()
    {
        $this->dir = storage_path('app/report-tasks');
        File::ensureDirectoryExists($this->dir);
    }

    /**
     * @param array<int, array{merchant: string, start_date?: string|null, end_date?: string|null}> $merchants
     * @return array<string, mixed>
     */
    public function create(array $merchants, int $userId): array
    {
        $id = (string) Str::uuid();
        $merchantItems = [];
        $totalMatched = 0;

        foreach ($merchants as $item) {
            $merchant = trim((string) ($item['merchant'] ?? ''));
            if ($merchant === '') {
                continue;
            }

            $startDate = $this->normalizeDate($item['start_date'] ?? null);
            $endDate = $this->normalizeDate($item['end_date'] ?? null);

            $query = SheetOrder::query()
                ->where('merchant', 'like', '%'.$merchant.'%')
                ->whereRaw('LOWER(TRIM(status)) = ?', ['scheduled'])
                ->whereNotNull('code')
                ->where('code', '!=', '');

            if ($startDate !== null) {
                $query->whereDate('order_date', '>=', $startDate);
            }
            if ($endDate !== null) {
                $query->whereDate('order_date', '<=', $endDate);
            }

            $matchedOrders = $query
                ->get(['id', 'order_no', 'code', 'status', 'order_date'])
                ->map(fn (SheetOrder $order) => [
                    'id' => (int) $order->id,
                    'order_no' => (string) ($order->order_no ?? ''),
                    'code' => (string) ($order->code ?? ''),
                    'status' => (string) ($order->status ?? ''),
                    'order_date' => $order->order_date,
                ])
                ->values();

            $orderIds = $matchedOrders->pluck('id')->all();
            $matchCount = $matchedOrders->count();
            $totalMatched += $matchCount;

            $merchantItems[] = [
                'merchant' => $merchant,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'matched_order_ids' => $orderIds,
                'matched_orders' => $matchedOrders->all(),
                'matched_count' => $matchCount,
            ];
        }

        $task = [
            'id' => $id,
            'user_id' => $userId,
            'type' => 'report_delivery_workflow',
            'status' => 'waiting_confirmation',
            'current_step' => 'confirm_delivery',
            'confirmation_required' => true,
            'message' => 'Step 1 ready: confirm to mark matched scheduled+coded orders as Delivered.',
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'merchants' => $merchantItems,
            'summary' => [
                'merchants_count' => count($merchantItems),
                'total_matched_orders' => $totalMatched,
            ],
            'report_links' => [],
            'logs' => [
                [
                    'time' => now()->toIso8601String(),
                    'event' => 'task_created',
                    'details' => "Matched {$totalMatched} scheduled orders with code.",
                ],
            ],
        ];

        $this->save($task);

        return $task;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $id, ?int $userId = null): ?array
    {
        $path = $this->path($id);
        if (! File::exists($path)) {
            return null;
        }

        $decoded = json_decode((string) File::get($path), true);
        if (! is_array($decoded)) {
            return null;
        }

        if ($userId !== null && (int) ($decoded['user_id'] ?? 0) !== $userId) {
            return null;
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function confirm(string $id, ?int $userId = null): ?array
    {
        $task = $this->get($id, $userId);
        if ($task === null) {
            return null;
        }

        $currentStep = (string) ($task['current_step'] ?? '');
        $merchantRows = is_array($task['merchants'] ?? null) ? $task['merchants'] : [];
        $allOrderIds = collect($merchantRows)
            ->flatMap(fn ($item) => is_array($item['matched_order_ids'] ?? null) ? $item['matched_order_ids'] : [])
            ->map(fn ($v) => (int) $v)
            ->filter(fn (int $v) => $v > 0)
            ->unique()
            ->values()
            ->all();

        if ($currentStep === 'confirm_delivery') {
            $affected = 0;
            if ($allOrderIds !== []) {
                $affected = SheetOrder::query()
                    ->whereIn('id', $allOrderIds)
                    ->update(['status' => 'Delivered']);
            }

            $task['current_step'] = 'confirm_remitted';
            $task['status'] = 'waiting_confirmation';
            $task['confirmation_required'] = true;
            $task['message'] = 'Step 2 ready: confirm to mark agent as remitted for delivered orders.';
            $task['logs'][] = [
                'time' => now()->toIso8601String(),
                'event' => 'status_marked_delivered',
                'details' => "Marked {$affected} orders as Delivered.",
            ];
        } elseif ($currentStep === 'confirm_remitted') {
            $affected = 0;
            if ($allOrderIds !== []) {
                $affected = SheetOrder::query()
                    ->whereIn('id', $allOrderIds)
                    ->whereRaw('LOWER(TRIM(status)) = ?', ['delivered'])
                    ->update(['agent' => 'remitted']);
            }

            $task['current_step'] = 'completed';
            $task['status'] = 'completed';
            $task['confirmation_required'] = false;
            $task['message'] = 'Task completed. You can now download reports per merchant.';
            $task['report_links'] = $this->buildReportLinks($merchantRows);
            $task['logs'][] = [
                'time' => now()->toIso8601String(),
                'event' => 'agent_marked_remitted',
                'details' => "Marked {$affected} orders as remitted.",
            ];
        } else {
            $task['message'] = 'Task is already completed.';
        }

        $task['updated_at'] = now()->toIso8601String();
        $this->save($task);

        return $task;
    }

    /**
     * @param array<int, array<string, mixed>> $merchants
     * @return array<int, array{merchant: string, url: string}>
     */
    private function buildReportLinks(array $merchants): array
    {
        $links = [];
        foreach ($merchants as $item) {
            $merchant = trim((string) ($item['merchant'] ?? ''));
            if ($merchant === '') {
                continue;
            }

            $query = array_filter([
                'merchant' => $merchant,
                'start_date' => $item['start_date'] ?? null,
                'end_date' => $item['end_date'] ?? null,
                'date_field' => 'delivery_date',
            ], fn ($v) => $v !== null && $v !== '');

            $url = '/reports/financial/export';
            if ($query !== []) {
                $url .= '?'.http_build_query($query);
            }

            $links[] = ['merchant' => $merchant, 'url' => $url];
        }

        return $links;
    }

    private function save(array $task): void
    {
        File::put($this->path((string) $task['id']), json_encode($task, JSON_PRETTY_PRINT));
    }

    private function path(string $id): string
    {
        return $this->dir.'/'.$id.'.json';
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $v = trim($value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : null;
    }
}
