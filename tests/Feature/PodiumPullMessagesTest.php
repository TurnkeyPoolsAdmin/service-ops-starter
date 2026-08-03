<?php

use App\Models\Setting;
use App\Services\Podium\PodiumApi;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    Setting::put('podium_client_id', 'cid');
    Setting::put('podium_client_secret', 'sec', sensitive: true);
    Setting::put('podium_refresh_token', 'rt', sensitive: true);
    Setting::put('podium_location_uid', 'loc-1');
});

it('pulls the last messages for a phone by matching the conversation channel', function () {
    Http::fake([
        '*/oauth/token' => Http::response(['access_token' => 'tok', 'refresh_token' => 'rt'], 200),
        // More specific pattern first — first match wins in Http::fake.
        '*/v4/conversations/c-match/messages' => Http::response(['data' => [[
            'items' => [
                ['type' => 'message', 'body' => 'Hi, is Tuesday open?', 'createdAt' => '2026-06-24T18:00:00Z', 'sourceType' => 'inbound'],
                ['type' => 'message', 'body' => 'Yes — 2pm works.', 'createdAt' => '2026-06-24T18:05:00Z', 'sourceType' => 'outbound'],
            ],
        ]]], 200),
        '*/v4/conversations?*' => Http::response(['data' => [
            ['uid' => 'c-other', 'channel' => ['type' => 'phone', 'identifier' => '+13105550000']],
            ['uid' => 'c-match', 'channel' => ['type' => 'phone', 'identifier' => '+18185551234']],
        ], 'metadata' => ['nextCursor' => null]], 200),
    ]);

    $messages = app(PodiumApi::class)->recentMessagesForPhone('818-555-1234', 5);

    // Newest first.
    expect($messages)->toHaveCount(2)
        ->and($messages[0]['body'])->toBe('Yes — 2pm works.')
        ->and($messages[0]['direction'])->toBe('outbound')
        ->and($messages[1]['body'])->toBe('Hi, is Tuesday open?')
        ->and($messages[1]['direction'])->toBe('inbound');
});

it('pulls the latest thread by conversation uid, newest first', function () {
    Http::fake([
        '*/oauth/token' => Http::response(['access_token' => 'tok', 'refresh_token' => 'rt'], 200),
        '*/v4/conversations/conv-9/messages' => Http::response(['data' => [[
            'items' => [
                ['type' => 'message', 'body' => 'older', 'createdAt' => '2026-06-24T18:00:00Z', 'sourceType' => 'INBOUND'],
                ['type' => 'message', 'body' => 'newer', 'createdAt' => '2026-06-24T18:05:00Z', 'sourceType' => 'OUTBOUND'],
            ],
        ]]], 200),
    ]);

    $messages = app(PodiumApi::class)->latestMessagesByConversation('conv-9', 5);

    expect($messages[0]['body'])->toBe('newer')
        ->and($messages[0]['direction'])->toBe('outbound')   // case-insensitive
        ->and($messages[1]['body'])->toBe('older');
});

it('returns nothing when no conversation matches the phone', function () {
    Http::fake([
        '*/oauth/token' => Http::response(['access_token' => 'tok', 'refresh_token' => 'rt'], 200),
        '*/v4/conversations*' => Http::response(['data' => [
            ['uid' => 'c-other', 'channel' => ['type' => 'phone', 'identifier' => '+13105550000']],
        ], 'metadata' => ['nextCursor' => null]], 200),
    ]);

    expect(app(PodiumApi::class)->recentMessagesForPhone('+18185551234'))->toBe([]);
});
