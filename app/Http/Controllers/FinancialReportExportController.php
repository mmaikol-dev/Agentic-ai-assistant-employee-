<?php

namespace App\Http\Controllers;

use App\Models\SheetOrder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinancialReportExportController extends Controller
{
    public function download(Request $request): BinaryFileResponse|StreamedResponse
    {
        $validated = $request->validate([
            'merchant' => ['nullable', 'string'],
            'country' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'agent' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'date_field' => ['nullable', 'in:order_date,delivery_date'],
        ]);

        $dateField = (string) ($validated['date_field'] ?? 'order_date');
        $query = SheetOrder::query()
            ->whereRaw('LOWER(TRIM(status)) = ?', ['delivered']);

        if (! empty($validated['agent'])) {
            $agent = strtolower(trim((string) $validated['agent']));
            if (in_array($agent, ['remitted', 'remittted'], true)) {
                $query->whereRaw('LOWER(TRIM(agent)) in (?, ?)', ['remitted', 'remittted']);
            } else {
                $query->whereRaw('LOWER(TRIM(agent)) = ?', [$agent]);
            }
        } else {
            // Default financial report export scope to remitted orders.
            $query->whereRaw('LOWER(TRIM(agent)) in (?, ?)', ['remitted', 'remittted']);
        }

        if (! empty($validated['merchant'])) {
            $query->where('merchant', 'like', '%' . $validated['merchant'] . '%');
        }
        if (! empty($validated['country'])) {
            $query->where('country', $validated['country']);
        }
        if (! empty($validated['city'])) {
            $query->where('city', 'like', '%' . $validated['city'] . '%');
        }
        if (! empty($validated['start_date'])) {
            $query->whereDate($dateField, '>=', $validated['start_date']);
        }
        if (! empty($validated['end_date'])) {
            $query->whereDate($dateField, '<=', $validated['end_date']);
        }

        $orders = $query->orderByDesc($dateField)->get();

        $amounts = $orders->map(function ($order) {
            $raw = $order->amount;
            if (is_numeric($raw)) {
                return (float) $raw;
            }

            return (float) preg_replace('/[^0-9.\-]/', '', (string) $raw);
        });

        $totalRevenue = round($amounts->sum(), 2);
        $totalOrders = $orders->count();
        $averageOrderValue = $totalOrders > 0 ? round($totalRevenue / $totalOrders, 2) : 0.0;
        $safeMerchant = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string) ($validated['merchant'] ?? 'all'));
        $timestamp = now()->format('Ymd_His');

        if (class_exists(\Maatwebsite\Excel\Facades\Excel::class)) {
            $filename = 'financial_report_' . $safeMerchant . '_' . $timestamp . '.xlsx';
            $rows = $orders->map(function ($order) {
                return [
                    (string) ($order->order_no ?? ''),
                    (string) ($order->order_date ?? ''),
                    (string) ($order->delivery_date ?? ''),
                    (string) ($order->merchant ?? ''),
                    (string) ($order->client_name ?? ''),
                    (string) ($order->product_name ?? ''),
                    (string) ($order->quantity ?? ''),
                    (string) ($order->amount ?? ''),
                    (string) ($order->status ?? ''),
                    (string) ($order->city ?? ''),
                    (string) ($order->country ?? ''),
                    (string) ($order->phone ?? ''),
                    (string) ($order->agent ?? ''),
                ];
            })->all();

            $headings = [
                'Order No',
                'Order Date',
                'Delivery Date',
                'Merchant',
                'Client Name',
                'Product Name',
                'Quantity',
                'Amount',
                'Status',
                'City',
                'Country',
                'Phone',
                'Agent',
            ];

            $export = new class($rows, $headings) implements
                \Maatwebsite\Excel\Concerns\FromArray,
                \Maatwebsite\Excel\Concerns\WithHeadings {
                public function __construct(
                    private readonly array $rows,
                    private readonly array $headings
                ) {}

                public function array(): array
                {
                    return $this->rows;
                }

                public function headings(): array
                {
                    return $this->headings;
                }
            };

            return \Maatwebsite\Excel\Facades\Excel::download(
                $export,
                $filename,
                \Maatwebsite\Excel\Excel::XLSX
            );
        }

        $filename = 'financial_report_' . $safeMerchant . '_' . $timestamp . '.csv';

        return response()->streamDownload(function () use ($orders, $validated, $dateField, $totalOrders, $totalRevenue, $averageOrderValue): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fputcsv($handle, ['Financial Report']);
            fputcsv($handle, ['Generated At', now()->toDateTimeString()]);
            fputcsv($handle, ['Merchant Filter', $validated['merchant'] ?? 'All']);
            fputcsv($handle, ['Country Filter', $validated['country'] ?? 'All']);
            fputcsv($handle, ['City Filter', $validated['city'] ?? 'All']);
            fputcsv($handle, ['Agent Filter', $validated['agent'] ?? 'remitted/remittted (default)']);
            fputcsv($handle, ['Date Field', $dateField]);
            fputcsv($handle, ['Start Date', $validated['start_date'] ?? 'N/A']);
            fputcsv($handle, ['End Date', $validated['end_date'] ?? 'N/A']);
            fputcsv($handle, ['Total Orders', $totalOrders]);
            fputcsv($handle, ['Total Revenue', $totalRevenue]);
            fputcsv($handle, ['Average Order Value', $averageOrderValue]);
            fputcsv($handle, []);

            fputcsv($handle, [
                'Order No',
                'Order Date',
                'Delivery Date',
                'Merchant',
                'Client Name',
                'Product Name',
                'Quantity',
                'Amount',
                'Status',
                'City',
                'Country',
                'Phone',
                'Agent',
            ]);

            foreach ($orders as $order) {
                fputcsv($handle, [
                    $this->csvSafe((string) ($order->order_no ?? '')),
                    (string) ($order->order_date ?? ''),
                    (string) ($order->delivery_date ?? ''),
                    $this->csvSafe((string) ($order->merchant ?? '')),
                    $this->csvSafe((string) ($order->client_name ?? '')),
                    $this->csvSafe((string) ($order->product_name ?? '')),
                    (string) ($order->quantity ?? ''),
                    (string) ($order->amount ?? ''),
                    $this->csvSafe((string) ($order->status ?? '')),
                    $this->csvSafe((string) ($order->city ?? '')),
                    $this->csvSafe((string) ($order->country ?? '')),
                    $this->csvSafe((string) ($order->phone ?? '')),
                    $this->csvSafe((string) ($order->agent ?? '')),
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function csvSafe(string $value): string
    {
        $trimmed = ltrim($value);
        if ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@'], true)) {
            return "'" . $value;
        }

        return $value;
    }
}
