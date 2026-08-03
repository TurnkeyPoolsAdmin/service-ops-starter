<?php

namespace App\Console\Commands;

use App\Models\ConnectorHealth;
use App\Models\Setting;
use App\Services\Health\ConnectorHealthChecker;
use App\Services\Health\ConnectorStatus;
use App\Services\Telegram\ConnectorHealthAlert;
use App\Services\Telegram\TelegramNotifier;
use App\Support\AlertWindow;
use Illuminate\Console\Command;

/**
 * Hourly sweep that probes every configured external connector and posts to the
 * group chat when one BREAKS or RECOVERS — never while it merely stays broken.
 *
 * Alerting on the transition (not the state) is the whole point: an hourly
 * "Podium is still down" would be muted within a day, which is the same
 * failure mode as the silent counter this replaced. Self-gates on the shared
 * telegram_alerts_enabled toggle, per the one-bot convention, and on
 * App\Support\AlertWindow — the probe still runs and still records state
 * overnight, but the POST waits for 7am.
 */
class CheckConnectorHealth extends Command
{
    protected $signature = 'connectors:health-check';

    protected $description = 'Probe external connectors (Podium, Quo, Google Ads, Plaid, Stripe) and alert on any that broke or recovered.';

    public function handle(
        ConnectorHealthChecker $checker,
        TelegramNotifier $telegram,
        ConnectorHealthAlert $alertBuilder,
    ): int {
        $statuses = $checker->check();

        if ($statuses === []) {
            $this->info('No configured connectors to check.');

            return self::SUCCESS;
        }

        /** @var array<int, ConnectorStatus> $brokeNow */
        $brokeNow = [];
        /** @var array<int, ConnectorStatus> $recoveredNow */
        $recoveredNow = [];

        foreach ($statuses as $status) {
            $record = ConnectorHealth::firstOrNew(['connector' => $status->key]);
            $newStatus = $status->healthy ? ConnectorHealth::OK : ConnectorHealth::DOWN;

            if ($record->status !== $newStatus) {
                $record->status_changed_at = now();
            }

            $record->status = $newStatus;
            $record->error = $status->error;
            $record->checked_at = now();
            $record->save();

            if (! $record->needsAlert()) {
                continue;
            }

            // First ever sighting of a healthy connector is the baseline, not a
            // recovery — record it silently so we don't announce "restored" for
            // something that was never known to be broken.
            if ($record->last_alerted_status === null && $status->healthy) {
                $record->update(['last_alerted_status' => $newStatus]);

                continue;
            }

            $status->healthy ? $recoveredNow[] = $status : $brokeNow[] = $status;
        }

        $this->report($statuses, $brokeNow, $recoveredNow);

        if ($brokeNow === [] && $recoveredNow === []) {
            return self::SUCCESS;
        }

        // The probe itself always runs and always records state; only the POST
        // is held. Nothing announced means nothing is marked announced, so a
        // connector that breaks at 2am is still announced on the 7am run —
        // last_alerted_status is only stamped by a successful send.
        if (Setting::get('telegram_alerts_enabled', '1') === '0'
            || ! $telegram->configured()
            || ! AlertWindow::open()) {
            return self::SUCCESS;
        }

        if ($brokeNow !== []) {
            $this->announce($telegram->send($alertBuilder->buildDown($brokeNow))->success, $brokeNow, ConnectorHealth::DOWN);
        }

        if ($recoveredNow !== []) {
            $this->announce($telegram->send($alertBuilder->buildRecovered($recoveredNow))->success, $recoveredNow, ConnectorHealth::OK);
        }

        return self::SUCCESS;
    }

    /**
     * Only stamp last_alerted_status on a SUCCESSFUL post, so a Telegram outage
     * means we retry next hour rather than swallowing the alert forever.
     *
     * @param  array<int, ConnectorStatus>  $statuses
     */
    private function announce(bool $sent, array $statuses, string $status): void
    {
        if (! $sent) {
            $this->warn('Telegram post failed — will retry on the next run.');

            return;
        }

        ConnectorHealth::query()
            ->whereIn('connector', array_map(fn (ConnectorStatus $s) => $s->key, $statuses))
            ->update(['last_alerted_status' => $status]);
    }

    /**
     * @param  array<int, ConnectorStatus>  $statuses
     * @param  array<int, ConnectorStatus>  $broke
     * @param  array<int, ConnectorStatus>  $recovered
     */
    private function report(array $statuses, array $broke, array $recovered): void
    {
        foreach ($statuses as $status) {
            $status->healthy
                ? $this->info("[ok]   {$status->label}")
                : $this->error("[down] {$status->label} — {$status->error}");
        }

        $this->line(sprintf('%d checked, %d newly down, %d recovered.', count($statuses), count($broke), count($recovered)));
    }
}
