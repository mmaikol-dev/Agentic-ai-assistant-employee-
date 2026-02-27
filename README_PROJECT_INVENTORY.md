# Project Inventory (What You Used)

This file summarizes the technologies, frameworks, AI runtime components, and custom tooling currently used in this project.

## Core Stack
- PHP 8.2+
- Laravel 12
- Inertia.js (Laravel + React)
- React 19 + TypeScript
- Vite 7
- Tailwind CSS 4

## Backend Packages (Composer)
- `laravel/framework`
- `inertiajs/inertia-laravel`
- `laravel/fortify`
- `laravel/mcp`
- `laravel/wayfinder`
- `laravel/tinker`
- `maatwebsite/excel`
- `sendgrid/sendgrid`
- `wasenderapi/wasenderapi-laravel`

## Dev/Test/Lint Packages
- `pestphp/pest`
- `pestphp/pest-plugin-laravel`
- `laravel/pint`
- `laravel/pail`
- `laravel/sail`
- `fakerphp/faker`
- `mockery/mockery`
- `nunomaduro/collision`

## Frontend Libraries
- `@inertiajs/react`
- `react`, `react-dom`, `typescript`
- `tailwindcss`, `@tailwindcss/vite`, `tailwind-merge`, `tw-animate-css`
- `lucide-react`
- Radix UI packages (`@radix-ui/*`)
- `@headlessui/react`
- `class-variance-authority`, `clsx`
- `input-otp`

## Frontend Tooling
- `vite`
- `eslint`, `@eslint/js`, `typescript-eslint`, `eslint-plugin-react`, `eslint-plugin-react-hooks`, `eslint-plugin-import`
- `prettier`, `prettier-plugin-tailwindcss`
- `concurrently`

## AI / Agent Runtime
- Ollama integration (`/api/chat`)
- Dynamic model routing for coding prompts (`OLLAMA_CODING_MODEL`)
- Skills loading from `resources/ai/skills`
- Tool policy/risk gating
- Planner pass before execution
- Tool orchestrator with retry strategy
- Tool critic/evaluation layer
- Long-term memory service

Main services used:
- `AgentMemoryService`
- `AgentPlannerService`
- `AgentToolPolicyService`
- `ChatMemoryService`
- `DynamicToolRegistryService`
- `McpToolInvokerService`
- `OllamaSkillLoader`
- `OllamaToolRunner`
- `ReportTaskService`
- `TaskService`
- `ToolCriticService`
- `ToolExecutionOrchestrator`

## MCP Server
- `OrdersServer` (mounted via route `/mcp/orders`)

## MCP Tools Used/Available
- `CallCenterDailyReportTool`
- `CallCenterMonthlyReportTool`
- `CreateOrderTool`
- `CreateRequisitionRecordTool`
- `CreateUserRecordTool`
- `EditOrderTool`
- `FinancialReportTool`
- `GetBudgetTransactionRecordTool`
- `GetCategoryRecordTool`
- `GetOrderTool`
- `GetProductRecordTool`
- `GetRequisitionRecordTool`
- `GetSheetRecordTool`
- `GetUserRecordTool`
- `GetWhatsappRecordTool`
- `InventoryManagerTool`
- `ListBudgetTransactionRecordsTool`
- `ListCategoryRecordsTool`
- `ListOrdersTool`
- `ListProductRecordsTool`
- `ListRequisitionRecordsTool`
- `ListSheetRecordsTool`
- `ListUserRecordsTool`
- `ListWhatsappRecordsTool`
- `MerchantReportTool`
- `ModelSchemaWorkspaceTool`
- `ScaffoldMcpTool`
- `SendEmailTool`
- `SendGridEmailTool`
- `SendWhatsappMessageTool`
- `ShippingTrackerTool`
- `UpdateRequisitionRecordTool`
- `UpdateUserRecordTool`
- `UserManagementTool`
- `UserReportTool`
- `WarehouseManagerTool`
- `WorkspaceEditorTool`

## Skills Implemented
- `call-center-daily-report`
- `call-center-monthly-report`
- `create-requisition-record`
- `create-user-record`
- `financial-reporting`
- `get-budget-transaction-record`
- `get-category-record`
- `get-product-record`
- `get-requisition-record`
- `get-sheet-record`
- `get-user-record`
- `get-whatsapp-record`
- `inventory-manager`
- `list-budget-transaction-records`
- `list-category-records`
- `list-product-records`
- `list-requisition-records`
- `list-sheet-records`
- `list-user-records`
- `list-whatsapp-records`
- `merchant-report`
- `merchant-reporting`
- `order-mutation`
- `order-query`
- `scaffold-mcp`
- `task-creation`
- `tool-scaffolding`
- `update-requisition-record`
- `update-user-record`
- `user-management`
- `user-report`
- `whatsapp-messaging`
- `workspace-editor`

## Messaging / Integrations
- WhatsApp provider abstraction (`meta`, `twilio`, `africastalking`, `custom`)
- SendGrid email support

## Background/Async Processing
- Laravel queue jobs
- Task creation and task run tracking
- Stream-based chat responses (NDJSON)

## Build & Run Commands You Use
- `composer install`
- `npm install`
- `php artisan migrate`
- `npm run dev`
- `php artisan serve`
- `composer dev`
- `php artisan test`
- `composer test`

## Notes
- AI coding model routing can be enabled with:
  - `OLLAMA_CODING_MODEL=qwen3-coder-next:cloud`
  - `OLLAMA_AUTO_ROUTE_CODING_MODEL=true`
