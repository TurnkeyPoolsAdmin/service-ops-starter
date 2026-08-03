<?php

namespace App\Services\Podium;

use App\Models\Setting;
use App\Support\PhoneNumber;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin authenticated client for the Podium v4 API. Used by the OAuth setup flow
 * to discover the location and register the message webhook so the owner never
 * has to copy UIDs or fill the create-webhook form by hand.
 */
class PodiumApi
{
    public function __construct(private PodiumOAuth $oauth) {}

    /**
     * List the locations the authorized account can access.
     *
     * @return array<int, array{uid: ?string, organizationUid: ?string, name: ?string}>
     */
    public function listLocations(): array
    {
        $response = $this->client()?->get($this->url('/v4/locations'));
        if (! $response || ! $response->successful()) {
            Log::error('PodiumApi: listLocations failed', ['status' => $response?->status()]);

            return [];
        }

        // The API returns either a bare list or { data: [...] } depending on call.
        $json = $response->json() ?? [];

        return $json['data'] ?? $json['locations'] ?? (array_is_list($json) ? $json : []);
    }

    /**
     * Create (or recreate) the message webhook for a location pointing at our
     * handler. Returns the created webhook payload, or null on failure.
     */
    public function createMessageWebhook(string $url, string $secret, string $locationUid): ?array
    {
        $response = $this->client()?->post($this->url('/v4/webhooks'), [
            'eventTypes' => ['message.sent', 'message.received', 'message.failed'],
            'url' => $url,
            'secret' => $secret,
            'locationUid' => $locationUid,
        ]);

        if (! $response || ! $response->successful()) {
            Log::error('PodiumApi: createMessageWebhook failed', ['status' => $response?->status()]);

            return null;
        }

        return $response->json();
    }

    /**
     * Pull the most recent messages for a phone number directly from Podium —
     * no webhooks required. Finds the conversation whose channel identifier
     * matches the phone (scanning recent pages, since the relevant customer just
     * messaged sales), then reads that conversation's messages, newest first.
     *
     * @return array<int, array{direction: string, body: ?string, at: ?string}>
     */
    public function recentMessagesForPhone(string $phone, int $limit = 5, int $maxPages = 5): array
    {
        $client = $this->client();
        $location = Setting::get('podium_location_uid');
        $target = PhoneNumber::toE164($phone);
        if (! $client || blank($location) || $target === null) {
            return [];
        }

        $conversationUid = null;
        $cursor = null;
        for ($page = 0; $page < $maxPages; $page++) {
            $query = ['locationUid' => $location];
            if ($cursor) {
                $query['cursor'] = $cursor;
            }

            $response = $client->get($this->url('/v4/conversations'), $query);
            if (! $response->successful()) {
                break;
            }

            foreach ($response->json('data') ?? [] as $conv) {
                if (($conv['channel']['identifier'] ?? null) === $target) {
                    $conversationUid = $conv['uid'] ?? null;
                    break 2;
                }
            }

            $cursor = $response->json('metadata.nextCursor');
            if (blank($cursor)) {
                break;
            }
        }

        if (blank($conversationUid)) {
            return [];
        }

        return $this->latestMessagesByConversation($conversationUid, $limit);
    }

    /**
     * Latest messages for a known conversation, newest first. This is the fast
     * path: the conversation_uid comes from a captured webhook, so there's no
     * scanning — one request returns the live thread.
     *
     * @return array<int, array{direction: string, body: ?string, at: ?string}>
     */
    public function latestMessagesByConversation(string $conversationUid, int $limit = 5): array
    {
        $client = $this->client();
        if (! $client) {
            return [];
        }

        $response = $client->get($this->url('/v4/conversations/'.$conversationUid.'/messages'));
        if (! $response->successful()) {
            return [];
        }

        // The payload nests messages under data[].items[].
        $items = [];
        foreach ($response->json('data') ?? [] as $group) {
            foreach ($group['items'] ?? [] as $item) {
                if (($item['type'] ?? 'message') !== 'message') {
                    continue;
                }
                $items[] = [
                    'direction' => strtolower($item['sourceType'] ?? '') === 'outbound' ? 'outbound' : 'inbound',
                    'body' => $item['body'] ?? null,
                    'at' => $item['createdAt'] ?? null,
                ];
            }
        }

        usort($items, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));

        return array_slice($items, 0, $limit);
    }

    private function client(): ?PendingRequest
    {
        $token = $this->oauth->accessToken();
        if (blank($token)) {
            return null;
        }

        $headers = ['Authorization' => 'Bearer '.$token];
        if (filled($version = config('glass.podium.version'))) {
            $headers['podium-version'] = $version;
        }

        return Http::withHeaders($headers)->asJson()->timeout(10);
    }

    private function url(string $path): string
    {
        return rtrim(config('glass.podium.api_base'), '/').$path;
    }
}
