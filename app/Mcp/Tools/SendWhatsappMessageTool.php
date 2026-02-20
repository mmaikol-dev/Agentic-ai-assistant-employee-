<?php

namespace App\Mcp\Tools;

use App\Services\Whatsapp\WhatsappMessageSender;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class SendWhatsappMessageTool extends Tool
{
    protected string $description = 'Send WhatsApp message via WasenderAPI';

    public function schema(JsonSchema $schema): array
    {
        return [
            'to' => $schema->string()->description('Recipient phone number, e.g. +2547...'),
            'message' => $schema->string()->description('Message body to send'),
        ];
    }

    public function handle(Request $request, Response $response): Response
    {
        $args = $request->arguments();

        $validator = Validator::make($args, [
            'to' => ['required', 'string', 'min:8'],
            'message' => ['required', 'string', 'min:1', 'max:4096'],
        ]);

        if ($validator->fails()) {
            return $response->error($validator->errors()->first());
        }

        try {
            $result = app(WhatsappMessageSender::class)
                ->send((string) $args['to'], (string) $args['message']);
        } catch (\Throwable $e) {
            return $response->error('Failed to call WhatsApp sender: '.$e->getMessage());
        }

        if (! ($result['ok'] ?? false)) {
            $message = (string) ($result['error'] ?? 'Failed to send WhatsApp message.');
            $details = $result['body'] ?? null;
            if (is_array($details)) {
                $details = json_encode($details);
            }
            if (is_string($details) && $details !== '') {
                $message .= ' Details: '.$details;
            }

            return $response->error($message);
        }

        return $response->text(json_encode([
            'type' => 'whatsapp_message_sent',
            'to' => (string) $args['to'],
            'provider' => (string) config('services.whatsapp.provider'),
            'result' => $result,
        ]));
    }
}
