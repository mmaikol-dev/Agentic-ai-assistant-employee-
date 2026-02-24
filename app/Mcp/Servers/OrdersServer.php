<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CallCenterMonthlyReportTool;
use App\Mcp\Tools\CallCenterDailyReportTool;
use App\Mcp\Tools\GetBudgetTransactionRecordTool;
use App\Mcp\Tools\ListBudgetTransactionRecordsTool;
use App\Mcp\Tools\GetWhatsappRecordTool;
use App\Mcp\Tools\ListWhatsappRecordsTool;
use App\Mcp\Tools\GetSheetRecordTool;
use App\Mcp\Tools\ListSheetRecordsTool;
use App\Mcp\Tools\GetUserRecordTool;
use App\Mcp\Tools\ListUserRecordsTool;
use App\Mcp\Tools\GetProductRecordTool;
use App\Mcp\Tools\ListProductRecordsTool;
use App\Mcp\Tools\WarehouseManagerTool;
use App\Mcp\Tools\ShippingTrackerTool;
use App\Mcp\Tools\InventoryManagerTool;
use App\Mcp\Tools\ModelSchemaWorkspaceTool;
use App\Mcp\Tools\SendGridEmailTool;
use App\Mcp\Tools\SendEmailTool;
use App\Mcp\Tools\SendWhatsappMessageTool;
use App\Mcp\Tools\FinancialReportTool;
use App\Mcp\Tools\CreateOrderTool;
use App\Mcp\Tools\EditOrderTool;
use App\Mcp\Tools\GetOrderTool;
use App\Mcp\Tools\ListOrdersTool;
use App\Mcp\Tools\ScaffoldMcpTool;
use Laravel\Mcp\Server;

class OrdersServer extends Server
{
    protected string $name = 'Orders Server';
    protected string $version = '1.0.0';
    protected string $instructions = 'Manages operational data across orders, products, users, sheets, WhatsApp messages, and budget transactions. Use tools to list, query, create, and update records where supported.';

    protected array $tools = [
        CallCenterMonthlyReportTool::class,
        CallCenterDailyReportTool::class,
        GetBudgetTransactionRecordTool::class,
        ListBudgetTransactionRecordsTool::class,
        GetWhatsappRecordTool::class,
        ListWhatsappRecordsTool::class,
        GetSheetRecordTool::class,
        ListSheetRecordsTool::class,
        GetUserRecordTool::class,
        ListUserRecordsTool::class,
        GetProductRecordTool::class,
        ListProductRecordsTool::class,
        WarehouseManagerTool::class,
        ShippingTrackerTool::class,
        InventoryManagerTool::class,
        ModelSchemaWorkspaceTool::class,
        SendEmailTool::class,
        SendGridEmailTool::class,
        SendWhatsappMessageTool::class,
        FinancialReportTool::class,
        ListOrdersTool::class,
        GetOrderTool::class,
        CreateOrderTool::class,
        EditOrderTool::class,
        ScaffoldMcpTool::class,
    ];
}
