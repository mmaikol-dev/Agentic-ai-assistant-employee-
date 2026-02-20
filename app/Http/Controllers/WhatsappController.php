<?php

namespace App\Http\Controllers;

use App\Models\SheetOrder;
use App\Models\Whatsapp;
use App\Services\Whatsapp\WhatsappMessageSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class WhatsappController extends Controller
{
    public function sendChat(Request $request): JsonResponse
    {
        Log::info('Sending custom WhatsApp chat message', $request->all());

        $validated = $request->validate([
            'to' => 'required|string',
            'message' => 'required|string|max:4096',
        ]);

        $to = $validated['to'];
        $messageText = $validated['message'];
        $formattedPhone = $this->formatPhoneForWasender($to);

        if (! $formattedPhone) {
            Log::error('Invalid phone number format', ['to' => $to]);

            return response()->json([
                'success' => false,
                'error' => 'Invalid phone number format',
            ], 400);
        }

        Log::info("Formatted phone: {$formattedPhone}");

        try {
            $sender = app(WhatsappMessageSender::class);
            $result = $sender->send($formattedPhone, $messageText);
        } catch (\Throwable $e) {
            Log::error('Failed to send chat message', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => 'An error occurred while sending the message',
            ], 500);
        }

        if (! ($result['ok'] ?? false)) {
            Log::error('WhatsApp provider error', [
                'to' => $formattedPhone,
                'status' => $result['status'] ?? null,
                'body' => $result['body'] ?? null,
                'error' => $result['error'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'error' => (string) ($result['error'] ?? 'Failed to send message'),
                'status' => $result['status'] ?? null,
                'details' => $result['body'] ?? null,
            ], 500);
        }

        $providerPayload = $result['body'] ?? [];
        $messageId = is_array($providerPayload)
            ? data_get($providerPayload, 'data.key.id')
            : null;

        $existingChat = Whatsapp::where('to', $to)
            ->orWhere('to', $formattedPhone)
            ->first();

        $clientName = $existingChat->client_name ?? 'Customer';
        $storeName = $existingChat->store_name ?? 'CHAT';
        $ccAgents = $existingChat->cc_agents ?? null;

        $whatsapp = Whatsapp::create([
            'to' => $formattedPhone,
            'client_name' => $clientName,
            'store_name' => $storeName,
            'cc_agents' => $ccAgents,
            'message' => $messageText,
            'status' => 'sent',
            'sid' => $messageId,
        ]);

        Log::info('Message saved to database', [
            'id' => $whatsapp->id,
            'to' => $formattedPhone,
            'sid' => $messageId,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Message sent successfully',
            'sid' => $messageId,
            'data' => $whatsapp,
        ]);
    }

    public function webhook(Request $request): JsonResponse
    {
        Log::info('Wasender webhook received', $request->all());

        $data = $request->all();
        if (empty($data)) {
            Log::warning('Empty webhook data received');

            return response()->json(['status' => 'no_data'], 200);
        }

        if (! isset($data['event'])) {
            Log::warning("No 'event' field found in webhook data");

            return response()->json(['status' => 'no_event'], 200);
        }

        $event = $data['event'];
        Log::info("Webhook event type: {$event}");

        if ($event === 'chats.update') {
            $this->handleChatUpdateEvent($data);
        } elseif ($event === 'message.status' || $event === 'messages.update') {
            $this->handleMessageStatusEvent($data);
        } else {
            Log::info("Unhandled event type: {$event}");
        }

        return response()->json(['status' => 'success'], 200);
    }

    public function sendMessage(int $id): RedirectResponse
    {
        Log::info("Sending WhatsApp template for Order ID: {$id}");

        try {
            $order = SheetOrder::findOrFail($id);

            $clientName = $order->client_name ?? 'Client';
            $storeName = strtoupper((string) ($order->store_name ?? 'STORE'));
            $orderNo = (string) $order->order_no;
            $productName = (string) $order->product_name;
            $quantity = (int) ($order->quantity ?? 0);
            $amount = (float) ($order->amount ?? 0);
            $ccEmail = $order->cc_email ?? null;

            $phone = $this->getPhoneNumberForWasenderAPI($order->phone, $order->alt_no);
            if (! $phone) {
                return back()->with('error', 'Invalid phone number format');
            }

            $message = $this->createOrderMessage($clientName, $orderNo, $productName, $quantity, $amount, $storeName);

            $sender = app(WhatsappMessageSender::class);
            $result = $sender->send($phone, $message);

            if (! ($result['ok'] ?? false)) {
                Log::error('Template send failed', ['result' => $result, 'order_id' => $id]);

                return back()->with('error', (string) ($result['error'] ?? 'Failed to send WhatsApp message'));
            }

            $providerPayload = $result['body'] ?? [];
            $messageId = is_array($providerPayload)
                ? data_get($providerPayload, 'data.key.id')
                : null;

            Whatsapp::create([
                'to' => $phone,
                'client_name' => $clientName,
                'store_name' => $storeName,
                'cc_agents' => $ccEmail,
                'message' => $message,
                'status' => 'sent',
                'sid' => $messageId,
            ]);

            return back()->with('success', 'WhatsApp message sent successfully');
        } catch (\Throwable $e) {
            Log::error('WhatsApp sending failed', [
                'error' => $e->getMessage(),
                'order_id' => $id,
            ]);

            return back()->with('error', 'Failed to send WhatsApp message');
        }
    }

    private function handleChatUpdateEvent(array $data): void
    {
        $chats = $data['data']['chats'] ?? null;
        if (! $chats) {
            Log::warning('No chats data found in webhook payload');

            return;
        }

        $messages = $chats['messages'] ?? [];
        foreach ($messages as $msgWrapper) {
            try {
                $messageData = $msgWrapper['message'] ?? null;
                if (! $messageData) {
                    continue;
                }

                $key = $messageData['key'] ?? [];
                $messageId = $key['id'] ?? null;
                $fromMe = $key['fromMe'] ?? false;
                if ($fromMe) {
                    continue;
                }

                $from = $key['remoteJidAlt'] ?? $key['remoteJid'] ?? null;
                if ($from && str_contains($from, '@')) {
                    $from = explode('@', $from)[0];
                }

                $pushName = $messageData['pushName'] ?? 'UNKNOWN';
                $messageBody = $this->extractIncomingMessageBody($messageData['message'] ?? []);

                if (! $from || ! $messageId) {
                    Log::warning('Skipping message due to missing sender or message id', [
                        'from' => $from,
                        'messageId' => $messageId,
                    ]);

                    continue;
                }

                Whatsapp::create([
                    'to' => $from,
                    'client_name' => $pushName,
                    'store_name' => 'WEBHOOK',
                    'cc_agents' => null,
                    'message' => $messageBody,
                    'status' => 'received',
                    'sid' => $messageId,
                ]);
            } catch (\Throwable $e) {
                Log::error('Failed to process incoming webhook message', ['error' => $e->getMessage()]);
            }
        }
    }

    private function handleMessageStatusEvent(array $data): void
    {
        $statusData = $data['data'] ?? [];
        $messageId = $statusData['key']['id'] ?? null;
        $statusCode = $statusData['status'] ?? null;

        if (! $messageId || $statusCode === null) {
            Log::warning('Missing message status fields', ['data' => $statusData]);

            return;
        }

        $statusMap = [
            0 => 'error',
            1 => 'pending',
            2 => 'sent',
            3 => 'delivered',
            4 => 'read',
            5 => 'played',
        ];

        $statusText = $statusMap[$statusCode] ?? "unknown_{$statusCode}";
        Whatsapp::where('sid', $messageId)->update(['status' => $statusText]);
    }

    private function extractIncomingMessageBody(array $message): string
    {
        if (isset($message['conversation'])) {
            return (string) $message['conversation'];
        }
        if (isset($message['extendedTextMessage']['text'])) {
            return (string) $message['extendedTextMessage']['text'];
        }
        if (isset($message['imageMessage'])) {
            $caption = $message['imageMessage']['caption'] ?? '';

            return '[Image received]'.($caption ? ": {$caption}" : '');
        }
        if (isset($message['videoMessage'])) {
            $caption = $message['videoMessage']['caption'] ?? '';

            return '[Video received]'.($caption ? ": {$caption}" : '');
        }
        if (isset($message['audioMessage'])) {
            return '[Audio received]';
        }
        if (isset($message['documentMessage'])) {
            $fileName = $message['documentMessage']['fileName'] ?? 'document';

            return "[Document received: {$fileName}]";
        }
        if (isset($message['stickerMessage'])) {
            return '[Sticker received]';
        }

        return '[Unknown message type]';
    }

    private function handleMediaDecryption(array $mediaInfo, string $mediaType, string $messageId): void
    {
        $url = $mediaInfo['url'] ?? null;
        $mediaKey = $mediaInfo['mediaKey'] ?? null;
        if (! $url || ! $mediaKey) {
            throw new \RuntimeException('Media object is missing url or mediaKey.');
        }

        $encryptedData = file_get_contents($url);
        if ($encryptedData === false) {
            throw new \RuntimeException("Failed to download media from URL: {$url}");
        }

        $keys = $this->getDecryptionKeys($mediaKey, $mediaType);
        $iv = substr($keys, 0, 16);
        $cipherKey = substr($keys, 16, 32);
        $ciphertext = substr($encryptedData, 0, -10);

        $decryptedData = openssl_decrypt($ciphertext, 'aes-256-cbc', $cipherKey, OPENSSL_RAW_DATA, $iv);
        if ($decryptedData === false) {
            throw new \RuntimeException('Failed to decrypt media.');
        }

        $mimeType = $mediaInfo['mimetype'] ?? 'application/octet-stream';
        $extension = explode('/', $mimeType)[1] ?? 'bin';
        $filename = $mediaInfo['fileName'] ?? "{$messageId}.{$extension}";
        $storagePath = "whatsapp-media/{$filename}";
        Storage::put($storagePath, $decryptedData);

        Log::info('Media decrypted and saved', [
            'path' => $storagePath,
            'type' => $mediaType,
            'size' => strlen($decryptedData),
        ]);
    }

    private function getDecryptionKeys(string $mediaKey, string $mediaType): string
    {
        $info = match ($mediaType) {
            'image', 'sticker' => 'WhatsApp Image Keys',
            'video' => 'WhatsApp Video Keys',
            'audio' => 'WhatsApp Audio Keys',
            'document' => 'WhatsApp Document Keys',
            default => throw new \InvalidArgumentException("Invalid media type: {$mediaType}"),
        };

        return hash_hkdf('sha256', base64_decode($mediaKey), 112, $info, '');
    }

    private function getPhoneNumberForWasenderAPI(?string $primaryPhone, ?string $altPhone): ?string
    {
        $phone = $this->formatPhoneForWasender($primaryPhone);
        if (! $phone) {
            $phone = $this->formatPhoneForWasender($altPhone);
        }

        return $phone;
    }

    private function formatPhoneForWasender(?string $phoneNumber): ?string
    {
        if (! $phoneNumber) {
            return null;
        }

        $phone = preg_replace('/\D/', '', $phoneNumber);
        if (! $phone || strlen($phone) < 9) {
            return null;
        }

        if (! preg_match('/^(254|255)/', $phone)) {
            $phone = str_starts_with($phone, '0')
                ? '254'.substr($phone, 1)
                : '254'.$phone;
        }

        return strlen($phone) >= 12 && strlen($phone) <= 13
            ? $phone
            : null;
    }

    private function createOrderMessage(
        string $clientName,
        string $orderNo,
        string $productName,
        int $quantity,
        float $amount,
        string $storeName
    ): string {
        $currency = $storeName === 'RDL3' ? 'TZS' : 'KES';
        $formattedAmount = number_format($amount);
        $contactNumber = '0740801187';

        return <<<MESSAGE
*REALDEAL LOGISTICS - ORDER NOTIFICATION*

Hello {$clientName},

We tried contacting you regarding your order *{$orderNo}* but your phone was unreachable.

*Order Details:*
📦 Product: {$productName}
🔢 Quantity: {$quantity} pcs
💰 Amount: {$currency} {$formattedAmount}

*Please call us back on {$contactNumber}* to confirm your availability for delivery.

Thank you for choosing Realdeal Logistics!

_Delivering Excellence, Every Time._
MESSAGE;
    }
}

