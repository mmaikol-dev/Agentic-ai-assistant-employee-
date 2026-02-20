<?php

namespace App\Services\Whatsapp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use WasenderApi\Exceptions\WasenderApiException;
use WasenderApi\WasenderClient;

class WhatsappMessageSender
{
    public function send(string $to, string $message): array
    {
        $provider = config('services.whatsapp.provider', 'custom');

        return match ($provider) {
            'meta' => $this->sendViaMeta($to, $message),
            'twilio' => $this->sendViaTwilio($to, $message),
            'africastalking' => $this->sendViaAfricasTalking($to, $message),
            default => $this->sendViaCustom($to, $message),
        };
    }

    private function sendViaMeta(string $to, string $message): array
    {
        $phoneNumberId = (string) config('services.whatsapp.meta_phone_number_id');
        $token = (string) config('services.whatsapp.meta_access_token');

        if ($phoneNumberId === '' || $token === '') {
            return ['ok' => false, 'error' => 'Missing Meta WhatsApp credentials.'];
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->post("https://graph.facebook.com/v20.0/{$phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'text',
                'text' => ['body' => $message],
            ]);

        return ['ok' => $response->successful(), 'status' => $response->status(), 'body' => $response->json() ?? $response->body()];
    }

    private function sendViaTwilio(string $to, string $message): array
    {
        $sid = (string) config('services.whatsapp.twilio_account_sid');
        $token = (string) config('services.whatsapp.twilio_auth_token');
        $from = (string) config('services.whatsapp.twilio_from');

        if ($sid === '' || $token === '' || $from === '') {
            return ['ok' => false, 'error' => 'Missing Twilio WhatsApp credentials.'];
        }

        $response = Http::asForm()
            ->withBasicAuth($sid, $token)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                'From' => $from,
                'To' => $to,
                'Body' => $message,
            ]);

        return ['ok' => $response->successful(), 'status' => $response->status(), 'body' => $response->json() ?? $response->body()];
    }

    private function sendViaAfricasTalking(string $to, string $message): array
    {
        $username = (string) config('services.whatsapp.africastalking_username');
        $apiKey = (string) config('services.whatsapp.africastalking_api_key');
        $from = (string) config('services.whatsapp.africastalking_from');

        if ($username === '' || $apiKey === '') {
            return ['ok' => false, 'error' => 'Missing Africa\'s Talking credentials.'];
        }

        $response = Http::asForm()
            ->withHeaders(['apiKey' => $apiKey, 'Accept' => 'application/json'])
            ->post('https://api.africastalking.com/version1/messaging', [
                'username' => $username,
                'to' => $to,
                'message' => $message,
                'from' => $from,
            ]);

        return ['ok' => $response->successful(), 'status' => $response->status(), 'body' => $response->json() ?? $response->body()];
    }

    private function sendViaCustom(string $to, string $message): array
    {
        $apiKey = (string) config('services.whatsapp.custom_api_key');
        if ($apiKey === '') {
            $apiKey = (string) config('wasenderapi.api_key');
        }

        if ($apiKey === '') {
            return ['ok' => false, 'error' => 'Custom provider selected. Set WHATSAPP_CUSTOM_API_KEY or WASENDERAPI_API_KEY.'];
        }

        $formattedTo = $this->formatPhoneForWasender($to);
        if (! $formattedTo) {
            return ['ok' => false, 'error' => 'Invalid phone number format for Wasender API.'];
        }

        try {
            $client = new WasenderClient($apiKey);
            $response = $client->sendText($formattedTo, $message);
        } catch (WasenderApiException $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'status' => $e->getCode() > 0 ? $e->getCode() : null,
                'body' => $e->getResponse(),
                'request' => [
                    'to' => $formattedTo,
                    'message' => $message,
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => 'Failed to call Wasender SDK: '.$e->getMessage(),
                'request' => [
                    'to' => $formattedTo,
                    'message' => $message,
                ],
            ];
        }

        return ['ok' => true, 'status' => 200, 'body' => $response];
    }

    private function formatPhoneForWasender(string $phoneNumber): ?string
    {
        $phone = preg_replace('/\D/', '', $phoneNumber);
        if (! $phone || strlen($phone) < 9) {
            return null;
        }

        if (! preg_match('/^(254|255)/', $phone)) {
            $phone = Str::startsWith($phone, '0')
                ? '254'.substr($phone, 1)
                : '254'.$phone;
        }

        return strlen($phone) >= 12 && strlen($phone) <= 13 ? $phone : null;
    }
}
