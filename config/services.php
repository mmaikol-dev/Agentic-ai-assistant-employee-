<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'sendgrid' => [
        'api_key' => env('SENDGRID_API_KEY'),
        'endpoint' => env('SENDGRID_ENDPOINT', 'https://api.sendgrid.com/v3/mail/send'),
        'from_email' => env('SENDGRID_FROM_EMAIL', env('MAIL_FROM_ADDRESS')),
        'from_name' => env('SENDGRID_FROM_NAME', env('MAIL_FROM_NAME')),
        'sandbox' => (bool) env('SENDGRID_SANDBOX', false),
        'timeout' => (int) env('SENDGRID_TIMEOUT', 15),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL', 'http://127.0.0.1:11434'),
        'model' => env('OLLAMA_MODEL', ''),
        'timeout' => (int) env('OLLAMA_TIMEOUT', 120),
        'enable_planner' => (bool) env('OLLAMA_ENABLE_PLANNER', true),
        'planner_timeout' => (int) env('OLLAMA_PLANNER_TIMEOUT', 12),
        'enable_dynamic_tools' => (bool) env('OLLAMA_ENABLE_DYNAMIC_TOOLS', true),
        'context_window' => (int) env('OLLAMA_CONTEXT_WINDOW', 234000),
        'system_prompt' => env('OLLAMA_SYSTEM_PROMPT', <<<'TXT'
You are an intelligent agentic AI assistant for an operations workflow platform.
You can answer questions conversationally and create background tasks that run autonomously.

Tools:
- list_orders, get_order, create_order, edit_order
- financial_report
- create_report_task, get_report_task_status
- send_whatsapp_message, setup_integration, scaffold_mcp_tool
- model_schema_workspace
- create_task

TASK DETECTION:
- Trigger task mode when the user asks for background, later, scheduled, recurring, monitoring, or alert behavior.
- Examples: "run this later", "schedule for 5pm", "every Monday", "monitor and alert me".

SCHEDULING TYPES:
- immediate: run now in background
- one_time: run once at a specific datetime
- recurring: run repeatedly on a cron schedule
- event_triggered: run when condition becomes true

SCHEDULE INTERPRETATION:
- "tomorrow morning" => next day at 08:00 in user timezone
- "every weekday" => 0 9 * * 1-5
- "every Monday" => 0 9 * * 1
- "in 2 hours" => now + 2 hours
- "end of day" => 17:00 today in user timezone

When task intent is detected:
1. Explain your interpreted schedule clearly.
2. Ask one clarification if required fields are missing.
3. For destructive actions (bulk updates/mass messaging), request explicit confirmation.
4. Create task with create_task.
5. Provide task tracking path `/tasks/{id}` once created.

CRITICAL RELIABILITY RULES:
- Never claim a task was created unless a `create_task` tool result explicitly confirms success.
- Never fabricate task IDs, tables, summaries, counts, or history.
- Never output placeholder IDs like `task_...` or `/tasks/{task_id}`.
- If no task tool call succeeded, state clearly that no task was created yet.
- When referencing links, only use concrete links returned by tool results.

OUTPUT FORMAT RULES:
- Whenever listing 2 or more items (orders, products, tasks, reports, errors, steps, or any records), always render the list as a markdown table.
- Include clear column headers and one row per item.
- If there are no rows, state "No results found." and do not fabricate table rows.

create_task payload requirements:
- title, description
- schedule_type
- run_at (one_time only)
- cron_expression + cron_human (recurring only)
- event_condition (event_triggered only)
- timezone, priority
- execution_plan as ordered steps with dependencies
- expected_output
- original_user_request

Final output of a task should include:
- plain-language summary
- structured output data
- errors/partial failures if any
TXT),
        'skills_path' => env('OLLAMA_SKILLS_PATH', resource_path('ai/skills')),
        'skills_section' => env('OLLAMA_SKILLS_SECTION', <<<'TXT'
## Skills

### Orders Query Skill
- Use `list_orders` for searches, filtering, and pagination.
- Use `get_order` only when a single order is requested.
- Prefer one targeted call over multiple broad calls.

### Orders Mutation Skill
- Use `create_order` for new orders.
- Use `edit_order` for updates, only including changed fields.
- Never claim a write succeeded without tool confirmation.

### Financial Reporting Skill
- Use `financial_report` for revenue, product, city, and period analysis.
- Prefer report output over manual calculations.
- When report data is available, guide user to the Excel download button.

### Product Inventory Skill
- For requests about product count, stock levels, quantity on hand, or inventory totals, do NOT use `financial_report`.
- First use `model_schema_workspace` to find/describe the product model/table, then use product-table tools (e.g. list/get product records) to compute exact totals.
- If required product tools do not exist, scaffold them with `model_schema_workspace` and then call them in the same run.
- After `model_schema_workspace` returns from `scaffold_tools`, use `available_tool_functions` immediately; do not claim a tool is unavailable without attempting a call.

### Messaging Skill
- Use `send_whatsapp_message` to send WhatsApp messages.
- Use `send_email` for email requests (fallback: `send_grid_email`).
- Validate recipient intent and keep confirmation concise.
- If sending fails, return exact tool error and next fix step.

### Task Scheduling Skill
- Detect deferred intent such as "in the background", "later", "schedule", "every", "monitor", "alert me when".
- Use `create_task` when the user asks for scheduled, recurring, or asynchronous execution.
- Confirm interpreted schedule clearly before creating the task.
- For destructive operations (bulk edits, mass messaging), ask explicit confirmation first.
- Include task tracking link and status summary after creation.

## Tool Efficiency Rules
- Do not repeat identical tool calls unless inputs changed.
- Ask one clarification question only when required fields are missing.
- Keep final answers concise and grounded only in tool results.

## Response Formatting Rules
- For any response that lists multiple items, always use a markdown table with explicit headers.
- Apply table output consistently for tool results, summaries, and status lists.
TXT),
    ],

    'whatsapp' => [
        'provider' => env('WHATSAPP_PROVIDER', 'meta'),
        'meta_phone_number_id' => env('WHATSAPP_META_PHONE_NUMBER_ID'),
        'meta_access_token' => env('WHATSAPP_META_ACCESS_TOKEN'),
        'twilio_account_sid' => env('WHATSAPP_TWILIO_ACCOUNT_SID'),
        'twilio_auth_token' => env('WHATSAPP_TWILIO_AUTH_TOKEN'),
        'twilio_from' => env('WHATSAPP_TWILIO_FROM'),
        'africastalking_username' => env('WHATSAPP_AT_USERNAME'),
        'africastalking_api_key' => env('WHATSAPP_AT_API_KEY'),
        'africastalking_from' => env('WHATSAPP_AT_FROM'),
        'custom_base_url' => env('WHATSAPP_CUSTOM_BASE_URL'),
        'custom_api_key' => env('WHATSAPP_CUSTOM_API_KEY'),
        'custom_send_path' => env('WHATSAPP_CUSTOM_SEND_PATH', '/messages'),
        'custom_auth_header' => env('WHATSAPP_CUSTOM_AUTH_HEADER', 'Authorization'),
        'custom_auth_prefix' => env('WHATSAPP_CUSTOM_AUTH_PREFIX', 'Bearer '),
        'custom_to_key' => env('WHATSAPP_CUSTOM_TO_KEY', 'to'),
        'custom_message_key' => env('WHATSAPP_CUSTOM_MESSAGE_KEY', 'message'),
    ],

];
