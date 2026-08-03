<?php

namespace App\Services\Messaging\Providers;

use App\Services\RingCentral\RingCentralOAuth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Production SMS provider for RingCentral. Sends from the configured RingCentral
 * number using a server-to-server (JWT) access token. The $from passed in by the
 * outbox is the global sending number; RingCentral requires it to be an
 * SMS-enabled number owned by the authenticated extension, so we fall back to the
 * explicitly configured `ringcentral_from_e164` when one is set.
 *
 *   POST {server}/restapi/v1.0/account/~/extension/~/sms
 *   Header: Authorization: Bearer <access token>
 *   Body:   { "from": { "phoneNumber": <E.164> },
 *             "to":   [ { "phoneNumber": <E.164> } ],
 *             "text": <body> }
 *   Success: HTTP 200, body { "id": <int>, "messageStatus": ... }
 *
 * Reference: https://developers.ringcentral.com/api-reference/SMS/createSMSMessage
 */
class RingCentralProvider implements MessageProvider
{
    public function __construct(
        protected RingCentralOAuth $oauth,
        protected ?string $fromOverride = null,
    ) {}

    public function send(string $to, string $from, string $body): ProviderResult
    {
        $fromNumber = filled($this->fromOverride) ? $this->fromOverride : $from;

        if (blank($fromNumber)) {
            return ProviderResult::failed('config', 'RingCentral sending number is not configured.');
        }

        $token = $this->oauth->accessToken();
        if (blank($token)) {
            return ProviderResult::failed('config', 'Could not obtain a RingCentral access token.');
        }

        $url = $this->oauth->serverUrl().config('glass.ringcentral.sms_path');

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->post($url, [
                    'from' => ['phoneNumber' => $fromNumber],
                    'to' => [['phoneNumber' => $to]],
                    'text' => $body,
                ]);
        } catch (\Throwable $e) {
            Log::error('RingCentralProvider transport error', ['error' => $e->getMessage()]);

            return ProviderResult::failed('transport', $e->getMessage());
        }

        $json = $response->json() ?? [];

        if ($response->successful()) {
            $id = $json['id'] ?? null;

            return ProviderResult::ok($id !== null ? (string) $id : null, $json);
        }

        // RingCentral errors: { "errorCode": "...", "message": "...", "errors": [...] }
        return ProviderResult::failed(
            (string) ($json['errorCode'] ?? $response->status()),
            $json['message'] ?? data_get($json, 'errors.0.message') ?? 'RingCentral send failed',
            $json,
        );
    }
}
