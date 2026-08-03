<?php

namespace App\Jobs;

use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Models\ScheduledMessage;
use App\Models\Setting;
use App\Services\Messaging\Providers\MessageProviderFactory;
use App\Services\Messaging\Providers\ProviderResult;
use App\Services\Messaging\QuoConversationLinker;
use App\Services\Telegram\MessageSendFailedAlert;
use App\Services\Telegram\TelegramNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sends a single outbox row. Designed to be safe under retries and concurrent
 * workers:
 *  - Locks the row with a compare-and-set (queued -> processing). A second
 *    worker / retry for the same row finds it no longer queued and stops.
 *  - Re-fetches job + customer and re-validates state at SEND time, aborting to
 *    'skipped' when the world has changed (opt-out, cancellation, reschedule,
 *    work completed).
 *  - Retries are processor-driven: on a transient failure the row returns to
 *    'queued' with scheduled_for pushed out by a backoff, so the next
 *    messages:process-due tick re-dispatches it. After max attempts -> 'failed'.
 */
class SendSmsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $scheduledMessageId) {}

    public function handle(MessageProviderFactory $providers): void
    {
        // 1. Lock: atomic compare-and-set queued -> processing.
        $locked = ScheduledMessage::query()
            ->where('id', $this->scheduledMessageId)
            ->where('status', MessageStatus::Queued->value)
            ->update([
                'status' => MessageStatus::Processing->value,
                'attempt_count' => DB::raw('attempt_count + 1'),
                'last_attempt_at' => now(),
            ]);

        if ($locked === 0) {
            return; // Already taken, sent, canceled, or skipped.
        }

        /** @var ScheduledMessage $message */
        $message = ScheduledMessage::with(['job.customer', 'customer'])->find($this->scheduledMessageId);
        if (! $message) {
            return;
        }

        // 2. Re-validate at send time.
        if ($reason = $this->skipReason($message)) {
            $message->update([
                'status' => MessageStatus::Skipped->value,
                'skip_reason' => $reason,
            ]);

            return;
        }

        // 3. Send via the provider (per-row override, else the global default).
        $provider = $providers->make($message->provider);
        try {
            $result = $provider->send($message->to_phone_e164, $message->from_phone_e164, $message->rendered_body);
        } catch (\Throwable $e) {
            Log::error('SendSmsJob: provider threw', ['id' => $message->id, 'error' => $e->getMessage()]);
            $result = ProviderResult::failed('exception', $e->getMessage());
        }

        if ($result->success) {
            $message->update([
                'status' => MessageStatus::Sent->value,
                'sent_at' => now(),
                'provider_message_id' => $result->providerMessageId,
                'provider_response' => $result->rawResponse,
                'error_code' => null,
                'error_message' => null,
            ]);

            // Capture the Quo inbox conversation link (no-op for non-Quo sends).
            $object = data_get($result->rawResponse, 'data', []);
            if (is_array($object)) {
                app(QuoConversationLinker::class)->captureFromObject($message->customer, $object);
            }

            return;
        }

        $this->handleFailure($message, $result);
    }

    /** Returns a skip reason if the message should NOT be sent, else null. */
    private function skipReason(ScheduledMessage $message): ?string
    {
        $customer = $message->customer;
        if ($customer?->do_not_text) {
            return 'customer opted out';
        }

        // Per-lead auto-text pause: skip any AUTOMATED row still queued from before
        // the lead was paused (e.g. reminders awaiting the cron). Manual sends are
        // an explicit human choice and are never suppressed here.
        if ($customer?->auto_text_paused && $message->message_type !== MessageType::Manual) {
            return 'auto-texts paused for lead';
        }

        $job = $message->job;
        if ($job) {
            if (in_array($job->status->value, ['canceled', 'lost'], true)) {
                return 'job '.$job->status->value;
            }

            if ($message->message_type === MessageType::Reminder) {
                if ($job->work_completed_at !== null) {
                    return 'work already completed';
                }
                // Reschedule cancels old reminder rows, but double-guard: the
                // appointment must still match this row's scheduled basis.
                if ($job->scheduled_at === null) {
                    return 'no appointment scheduled';
                }
            }
        }

        if (blank($message->from_phone_e164)) {
            return 'no sending number configured';
        }

        return null;
    }

    private function handleFailure(ScheduledMessage $message, $result): void
    {
        $max = (int) config('glass.send.max_attempts', 3);
        $backoff = config('glass.send.backoff', [60, 300, 900]);

        if ($message->attempt_count >= $max) {
            $message->update([
                'status' => MessageStatus::Failed->value,
                'failed_at' => now(),
                'error_code' => $result->errorCode,
                'error_message' => $result->errorMessage,
                'provider_response' => $result->rawResponse,
            ]);

            $this->alertSendFailed($message);

            return;
        }

        // Transient: requeue with a backoff so the next processor tick retries it.
        $delaySeconds = $backoff[$message->attempt_count - 1] ?? end($backoff);
        $message->update([
            'status' => MessageStatus::Queued->value,
            'scheduled_for' => now()->addSeconds((int) $delaySeconds),
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage,
        ]);
    }

    /**
     * Post an immediate Telegram alert for a text that exhausted its retries.
     * Best-effort and never fatal — a dead outbox row is bad, but blowing up the
     * queue worker on top of it (and thereby stalling every OTHER text) is worse.
     * Self-gates on the shared telegram_alerts_enabled toggle like every alert.
     */
    private function alertSendFailed(ScheduledMessage $message): void
    {
        try {
            if (Setting::get('telegram_alerts_enabled', '1') === '0') {
                return;
            }

            $telegram = app(TelegramNotifier::class);
            if (! $telegram->configured()) {
                return;
            }

            $provider = $message->provider
                ?: (Setting::get('outgoing_provider') ?: config('glass.message_provider', 'log'));

            $telegram->send(app(MessageSendFailedAlert::class)->build($message, (string) $provider));
        } catch (\Throwable $e) {
            Log::error('SendSmsJob: send-failed alert threw', [
                'id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
