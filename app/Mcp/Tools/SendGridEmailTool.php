<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class SendGridEmailTool extends Tool
{
    protected string $description = 'Send emails via SendGrid API with support for HTML/text content, attachments, and multiple recipients';

    public function schema(JsonSchema $schema): array
    {
        return [
            'to' => $schema->array()
                ->items($schema->string()->format('email'))
                ->min(1)
                ->required()
                ->description('Required list of recipient email addresses.'),
            'subject' => $schema->string()->min(1)->max(255)->description('Email subject line.')->required(),
            'content' => $schema->string()->min(1)->description('Email body content (plain text or HTML).')->required(),
            'content_type' => $schema->string()->enum(['text/plain', 'text/html'])->default('text/plain')->description('Body MIME type.'),
            'from_email' => $schema->string()->format('email')->nullable()->description('Optional sender email; must be verified in SendGrid.'),
            'from_name' => $schema->string()->nullable()->description('Optional sender display name.'),
            'cc' => $schema->array()->items($schema->string()->format('email'))->nullable()->description('Optional CC recipients.'),
            'bcc' => $schema->array()->items($schema->string()->format('email'))->nullable()->description('Optional BCC recipients.'),
            'reply_to' => $schema->string()->format('email')->nullable()->description('Optional reply-to address.'),
            'sandbox' => $schema->boolean()->nullable()->description('Optional override for SendGrid sandbox mode.'),
            'confirmed' => $schema->boolean()->nullable()->description('Explicit confirmation for high-risk action'),
            'attachments' => $schema->array()
                ->items($schema->object([
                    'filename' => $schema->string()->required(),
                    'content' => $schema->string()->required()->description('Base64 encoded content.'),
                    'type' => $schema->string()->nullable()->description('MIME type, e.g. application/pdf'),
                    'disposition' => $schema->string()->enum(['attachment', 'inline'])->nullable(),
                    'content_id' => $schema->string()->nullable(),
                ]))
                ->nullable()
                ->description('Optional attachments array.'),
        ];
    }

    public function handle(Request $request, Response $response): Response
    {
        $args = $request->arguments();

        $validator = Validator::make($args, [
            'to' => ['required', 'array', 'min:1'],
            'to.*' => ['required', 'email'],
            'subject' => ['required', 'string', 'min:1', 'max:255'],
            'content' => ['required', 'string', 'min:1'],
            'content_type' => ['nullable', 'in:text/plain,text/html'],
            'from_email' => ['nullable', 'email'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'cc' => ['nullable', 'array'],
            'cc.*' => ['required', 'email'],
            'bcc' => ['nullable', 'array'],
            'bcc.*' => ['required', 'email'],
            'reply_to' => ['nullable', 'email'],
            'sandbox' => ['nullable', 'boolean'],
            'confirmed' => ['nullable', 'boolean'],
            'attachments' => ['nullable', 'array', 'max:15'],
            'attachments.*.filename' => ['required_with:attachments', 'string'],
            'attachments.*.content' => ['required_with:attachments', 'string'],
            'attachments.*.type' => ['nullable', 'string'],
            'attachments.*.disposition' => ['nullable', 'in:attachment,inline'],
            'attachments.*.content_id' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $response->error($validator->errors()->first());
        }

        $apiKey = (string) config('services.sendgrid.api_key');
        if ($apiKey === '') {
            return $response->error('Missing SENDGRID_API_KEY. Set it in your environment.');
        }

        $fromEmail = (string) ($args['from_email'] ?? config('services.sendgrid.from_email', config('mail.from.address')));
        $fromName = (string) ($args['from_name'] ?? config('services.sendgrid.from_name', config('mail.from.name')));

        if ($fromEmail === '') {
            return $response->error('Missing sender email. Set SENDGRID_FROM_EMAIL or pass from_email.');
        }

        $personalization = [
            'to' => array_map(
                static fn (string $email) => ['email' => $email],
                array_values($args['to'])
            ),
        ];

        if (! empty($args['cc'])) {
            $personalization['cc'] = array_map(
                static fn (string $email) => ['email' => $email],
                array_values($args['cc'])
            );
        }

        if (! empty($args['bcc'])) {
            $personalization['bcc'] = array_map(
                static fn (string $email) => ['email' => $email],
                array_values($args['bcc'])
            );
        }

        $contentType = (string) ($args['content_type'] ?? 'text/plain');
        $payload = [
            'personalizations' => [$personalization],
            'from' => array_filter([
                'email' => $fromEmail,
                'name' => $fromName !== '' ? $fromName : null,
            ]),
            'subject' => (string) $args['subject'],
            'content' => [[
                'type' => $contentType,
                'value' => (string) $args['content'],
            ]],
        ];

        if (! empty($args['reply_to'])) {
            $payload['reply_to'] = ['email' => (string) $args['reply_to']];
        }

        if (! empty($args['attachments']) && is_array($args['attachments'])) {
            $payload['attachments'] = array_map(static function (array $attachment): array {
                return array_filter([
                    'content' => $attachment['content'] ?? null,
                    'filename' => $attachment['filename'] ?? null,
                    'type' => $attachment['type'] ?? null,
                    'disposition' => $attachment['disposition'] ?? 'attachment',
                    'content_id' => $attachment['content_id'] ?? null,
                ], static fn ($value) => $value !== null && $value !== '');
            }, $args['attachments']);
        }

        $sandbox = array_key_exists('sandbox', $args)
            ? (bool) $args['sandbox']
            : (bool) config('services.sendgrid.sandbox', false);

        if ($sandbox) {
            $payload['mail_settings'] = ['sandbox_mode' => ['enable' => true]];
        }

        $endpoint = (string) config('services.sendgrid.endpoint', 'https://api.sendgrid.com/v3/mail/send');
        $timeout = (int) config('services.sendgrid.timeout', 15);

        try {
            $sendgridResponse = Http::timeout($timeout)
                ->withToken($apiKey)
                ->acceptJson()
                ->post($endpoint, $payload);
        } catch (\Throwable $e) {
            return $response->error('SendGrid request failed: '.$e->getMessage());
        }

        if (! $sendgridResponse->successful()) {
            $errorBody = $sendgridResponse->json();
            if (! is_array($errorBody)) {
                $errorBody = ['body' => $sendgridResponse->body()];
            }

            return $response->error(json_encode([
                'message' => 'SendGrid rejected the email request.',
                'status' => $sendgridResponse->status(),
                'details' => $errorBody,
            ]));
        }

        $messageIdHeader = $sendgridResponse->header('x-message-id');
        $messageId = is_array($messageIdHeader) ? ($messageIdHeader[0] ?? null) : $messageIdHeader;

        return $response->text(json_encode([
            'type' => 'send_grid_email',
            'ok' => true,
            'status' => $sendgridResponse->status(),
            'message' => 'Email accepted by SendGrid.',
            'message_id' => is_string($messageId) && $messageId !== '' ? $messageId : null,
            'sandbox' => $sandbox,
            'to_count' => count($args['to']),
            'response_headers' => [
                'x-message-id' => is_string($messageId) && $messageId !== '' ? $messageId : null,
            ],
        ]));
    }
}
