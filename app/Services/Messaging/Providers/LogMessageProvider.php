<?php

namespace App\Services\Messaging\Providers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Default provider for every non-production environment. Records the send to
 * the log and returns success with a synthetic provider id — but sends NO real
 * SMS. The outbox row itself is created/updated by the worker either way, so
 * the full pipeline is exercised without texting real customers.
 */
class LogMessageProvider implements MessageProvider
{
    public function send(string $to, string $from, string $body): ProviderResult
    {
        $id = 'log_'.Str::lower(Str::random(20));

        Log::channel(config('logging.default'))->info('[LogMessageProvider] SMS (not sent)', [
            'to' => $to,
            'from' => $from,
            'body' => $body,
            'provider_message_id' => $id,
        ]);

        return ProviderResult::ok($id, [
            'provider' => 'log',
            'to' => $to,
            'from' => $from,
            'note' => 'Logged only — no real SMS sent in this environment.',
        ]);
    }
}
