<?php

use App\Mcp\Servers\OrdersServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/orders', OrdersServer::class);