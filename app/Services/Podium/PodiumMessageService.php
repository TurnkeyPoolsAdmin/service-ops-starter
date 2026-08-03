<?php

namespace App\Services\Podium;

use App\Models\Customer;
use App\Models\PodiumMessage;
use App\Support\PhoneNumber;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PodiumMessageService
{
    /**
     * Record a Podium message and link it to a customer by phone when one
     * exists. Podium's webhook carries no stable message id (data.uid is null),
     * so we dedupe on a hash of the event fields — webhook redelivery of an
     * identical payload maps to the same row instead of creating a duplicate.
     *
     * @param  array  $data  keys: event_type, direction, channel_type,
     *                       contact_identifier, conversation_uid, body,
     *                       provider_message_id, occurred_at, raw_payload
     */
    public function record(array $data): PodiumMessage
    {
        // Only phone channels yield a usable lookup key; email/other store blank.
        $isPhone = ($data['channel_type'] ?? null) === 'phone';
        $contactE164 = $isPhone ? (PhoneNumber::toE164($data['contact_identifier'] ?? null) ?? '') : '';

        $customer = $contactE164
            ? Customer::where('phone_e164', $contactE164)->first()
            : null;

        $providerId = $data['provider_message_id'] ?? null;
        $hash = hash('sha256', implode('|', [
            $data['event_type'] ?? '',
            $data['contact_identifier'] ?? '',
            $data['occurred_at'] ?? '',
            $data['body'] ?? '',
        ]));

        $attributes = [
            'customer_id' => $customer?->id,
            'direction' => $data['direction'] ?? 'inbound',
            'channel_type' => $data['channel_type'] ?? null,
            'contact_phone_e164' => $contactE164,
            'business_phone_e164' => null,
            'conversation_uid' => $data['conversation_uid'] ?? null,
            'body' => $data['body'] ?? null,
            'occurred_at' => isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : now(),
            'raw_payload' => $data['raw_payload'] ?? null,
        ];

        // Dedupe on Podium's stable message id when present (real events include
        // it — the docs example wrongly shows it null), else on a content hash.
        // Keep the unused key column null so neither unique index can collide.
        if (filled($providerId)) {
            return PodiumMessage::updateOrCreate(
                ['provider_message_id' => $providerId],
                $attributes + ['event_hash' => null],
            );
        }

        return PodiumMessage::updateOrCreate(
            ['event_hash' => $hash],
            $attributes + ['provider_message_id' => null],
        );
    }

    /**
     * Most recent Podium messages for a phone number, newest first.
     *
     * @return Collection<int, PodiumMessage>
     */
    public function recentFor(?string $phone, int $limit = 5): Collection
    {
        $e164 = PhoneNumber::toE164($phone);
        if ($e164 === null) {
            return collect();
        }

        return PodiumMessage::where('contact_phone_e164', $e164)
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get();
    }
}
