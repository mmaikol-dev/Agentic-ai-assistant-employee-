<?php

use App\Mcp\Tools\CallCenterDailyReportTool;
use App\Mcp\Tools\CallCenterMonthlyReportTool;
use App\Services\McpToolInvokerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

beforeEach(function (): void {
    Schema::create('sheet_orders', function (Blueprint $table): void {
        $table->id();
        $table->string('order_no')->nullable();
        $table->dateTime('order_date')->nullable();
        $table->dateTime('delivery_date')->nullable();
        $table->string('amount')->nullable();
        $table->string('client_name')->nullable();
        $table->string('product_name')->nullable();
        $table->string('city')->nullable();
        $table->string('country')->nullable();
        $table->string('status')->nullable();
        $table->string('code')->nullable();
        $table->string('merchant')->nullable();
        $table->string('cc_email')->nullable();
        $table->timestamps();
    });
});

it('daily report includes only scheduled or delivered orders with code', function (): void {
    DB::table('sheet_orders')->insert([
        [
            'order_no' => 'A-1',
            'order_date' => '2026-02-20 10:00:00',
            'delivery_date' => '2026-02-20 10:00:00',
            'amount' => '10',
            'client_name' => 'Alice',
            'product_name' => 'Widget',
            'city' => 'Quito',
            'country' => 'EC',
            'status' => 'scheduled',
            'code' => 'C1',
            'merchant' => 'Shop',
            'cc_email' => 'agent@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'order_no' => 'A-2',
            'order_date' => '2026-02-20 11:00:00',
            'delivery_date' => '2026-02-20 11:00:00',
            'amount' => '20',
            'client_name' => 'Bob',
            'product_name' => 'Widget',
            'city' => 'Quito',
            'country' => 'EC',
            'status' => 'Delivered',
            'code' => 'C2',
            'merchant' => 'Shop',
            'cc_email' => 'agent@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'order_no' => 'A-3',
            'order_date' => '2026-02-20 12:00:00',
            'delivery_date' => '2026-02-20 12:00:00',
            'amount' => '30',
            'client_name' => 'Carol',
            'product_name' => 'Widget',
            'city' => 'Quito',
            'country' => 'EC',
            'status' => 'delivered',
            'code' => null,
            'merchant' => 'Shop',
            'cc_email' => 'agent@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'order_no' => 'A-4',
            'order_date' => '2026-02-20 13:00:00',
            'delivery_date' => '2026-02-20 13:00:00',
            'amount' => '40',
            'client_name' => 'Dan',
            'product_name' => 'Widget',
            'city' => 'Quito',
            'country' => 'EC',
            'status' => 'cancelled',
            'code' => 'C4',
            'merchant' => 'Shop',
            'cc_email' => 'agent@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $result = app(McpToolInvokerService::class)->invoke(CallCenterDailyReportTool::class, [
        'start_date' => '2026-02-20',
        'end_date' => '2026-02-20',
        'limit' => 50,
    ]);

    expect($result['type'])->toBe('call_center_daily_report');
    expect($result['total_orders'])->toBe(2);
    expect($result['listed_orders_count'])->toBe(2);
    expect($result['orders'])->toHaveCount(2);
    expect(array_keys($result['orders'][0]))->toBe([
        'order_no',
        'mpesa_code',
    ]);
});

it('monthly report requires month or date range', function (): void {
    $result = app(McpToolInvokerService::class)->invoke(CallCenterMonthlyReportTool::class, []);

    expect($result['type'])->toBe('error');
    expect($result['message'])->toContain('Provide month');
});

it('monthly report filters by month and call center email and groups by status', function (): void {
    DB::table('sheet_orders')->insert([
        [
            'order_no' => 'M-1',
            'order_date' => '2026-02-03 10:00:00',
            'delivery_date' => '2026-02-03 10:00:00',
            'amount' => '15',
            'client_name' => 'Alice',
            'product_name' => 'Product A',
            'city' => 'Lima',
            'country' => 'PE',
            'status' => 'Delivered',
            'code' => 'X1',
            'merchant' => 'Shop',
            'cc_email' => 'agent@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'order_no' => 'M-2',
            'order_date' => '2026-02-05 10:00:00',
            'delivery_date' => '2026-02-05 10:00:00',
            'amount' => '25',
            'client_name' => 'Bob',
            'product_name' => 'Product B',
            'city' => 'Lima',
            'country' => 'PE',
            'status' => 'scheduled',
            'code' => 'X2',
            'merchant' => 'Shop',
            'cc_email' => 'agent@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'order_no' => 'M-3',
            'order_date' => '2026-02-12 10:00:00',
            'delivery_date' => '2026-02-12 10:00:00',
            'amount' => '35',
            'client_name' => 'Carol',
            'product_name' => 'Product C',
            'city' => 'Lima',
            'country' => 'PE',
            'status' => 'cancelled',
            'code' => 'X3',
            'merchant' => 'Shop',
            'cc_email' => 'other@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'order_no' => 'M-4',
            'order_date' => '2026-01-25 10:00:00',
            'delivery_date' => '2026-01-25 10:00:00',
            'amount' => '45',
            'client_name' => 'Dan',
            'product_name' => 'Product D',
            'city' => 'Lima',
            'country' => 'PE',
            'status' => 'Delivered',
            'code' => 'X4',
            'merchant' => 'Shop',
            'cc_email' => 'agent@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $result = app(McpToolInvokerService::class)->invoke(CallCenterMonthlyReportTool::class, [
        'month' => '2026-02',
        'cc_email' => 'agent@example.com',
    ]);

    expect($result['type'])->toBe('call_center_monthly_report');
    expect($result['total_orders'])->toBe(2);
    expect(collect($result['status_breakdown'])->pluck('order_count', 'status')->all())->toBe([
        'delivered' => 1,
        'scheduled' => 1,
    ]);
});
