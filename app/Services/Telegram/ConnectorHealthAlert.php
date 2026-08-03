<?php

namespace App\Services\Telegram;

use App\Services\Health\ConnectorStatus;

/**
 * Builds the "a connector just broke" (and "it's back") alerts. Deliberately
 * leads with the business consequence rather than the technical error, because
 * the person reading the group chat needs to know whether customers are being
 * affected right now — "outgoing texts are failing" is actionable, "HTTP 400"
 * is not. The fix hint names the exact screen so nobody has to go hunting.
 */
class ConnectorHealthAlert
{
    /** @param array<int, ConnectorStatus> $down */
    public function buildDown(array $down): string
    {
        $lines = [
            count($down) === 1
                ? '🔌 <b>Connector down</b>'
                : '🔌 <b>'.count($down).' connectors down</b>',
            '',
        ];

        foreach ($down as $status) {
            $lines[] = '<b>'.e($status->label).'</b>';
            $lines[] = e((string) $status->error);
            if ($status->fixHint) {
                $lines[] = 'Fix: '.e($status->fixHint);
            }
            $lines[] = '';
        }

        $lines[] = 'This will keep failing silently until it is reconnected.';

        return implode("\n", $lines);
    }

    /** @param array<int, ConnectorStatus> $recovered */
    public function buildRecovered(array $recovered): string
    {
        $names = implode(', ', array_map(fn (ConnectorStatus $s) => e($s->label), $recovered));

        return "✅ <b>Connector restored</b>\n\n{$names} is working again.";
    }
}
