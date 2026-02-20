<?php

namespace App\Mcp\Servers;

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
    protected string $instructions = 'Manages the sheet_orders table. Use tools to create, edit, list and query orders.';

    protected array $tools = [
        SendWhatsappMessageTool::class,
        FinancialReportTool::class,
        ListOrdersTool::class,
        GetOrderTool::class,
        CreateOrderTool::class,
        EditOrderTool::class,
        ScaffoldMcpTool::class,
    ];
}
