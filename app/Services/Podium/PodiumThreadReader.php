<?php

namespace App\Services\Podium;

use App\Models\Setting;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Cache;

/**
 * Reads the latest Podium messages for a phone, oldest->newest.
 *
 * Webhook capture gives us the conversation_uid for a phone; we then pull that
 * conversation's latest messages live by uid (one fast call, no scanning) so
 * the panel always reflects the current thread — including messages sent at
 * stages our webhook automation may not cover. Falls back to the captured rows
 * themselves when the live pull is unavailable.
 *
 * Shared by the Intake and SendWelcome screens.
 */
class PodiumThreadReader
{
    public function __construct(
        private PodiumMessageService $messages,
        private PodiumApi $api,
    ) {}

    /**
     * @return array<int, array{direction: string, body: ?string, at: ?string}>
     */
    public function latestFor(?string $phone, int $limit = 5): array
    {
        $e164 = PhoneNumber::toE164($phone);
        if ($e164 === null) {
            return [];
        }

        $captured = $this->messages->recentFor($e164, $limit);
        $conversationUid = $captured->first()?->conversation_uid;

        // Fast path: pull the live thread by the known conversation uid.
        if ($conversationUid && filled(Setting::get('podium_refresh_token'))) {
            try {
                $live = Cache::remember(
                    'podium_thread_'.$conversationUid,
                    now()->addSeconds(60),
                    fn () => $this->api->latestMessagesByConversation($conversationUid, $limit),
                );
                if ($live !== []) {
                    return array_reverse($live); // API returns newest-first
                }
            } catch (\Throwable $e) {
                // fall through to captured rows
            }
        }

        // Fallback: the webhook-captured rows we already have (oldest->newest).
        return $captured
            ->reverse()
            ->map(fn ($m) => [
                'direction' => $m->direction,
                'body' => $m->body,
                'at' => optional($m->occurred_at)->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
