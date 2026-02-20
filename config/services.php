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
        'context_window' => (int) env('OLLAMA_CONTEXT_WINDOW', 234000),
        'system_prompt' => env('OLLAMA_SYSTEM_PROMPT'),
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

### Messaging Skill
- Use `send_whatsapp_message` to send WhatsApp messages.
- Use `send_email` for email requests (fallback: `send_grid_email`).
- Validate recipient intent and keep confirmation concise.
- If sending fails, return exact tool error and next fix step.

## Tool Efficiency Rules
- Do not repeat identical tool calls unless inputs changed.
- Ask one clarification question only when required fields are missing.
- Keep final answers concise and grounded only in tool results.
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
