<?php

namespace App\Services\Messaging;

use App\Enums\Language;
use App\Enums\TemplateKey;
use App\Models\Employee;
use App\Models\Job;
use App\Models\ScheduledMessage;
use App\Support\PhoneNumber;
use App\Support\Timezone;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Sends each technician a digest of their jobs the night before and the morning
 * of. Unlike the customer reminders, the digest is a planning tool, so it lists
 * EVERY open job on the tech's calendar for the target date regardless of when
 * it was booked — a job added today for tomorrow still belongs in tonight's
 * plan. (The customer-facing ReminderScheduler keeps the same-day/next-day-booked
 * guards; the tech digest intentionally does not.) One digest per tech per phase
 * per day, enforced by a deterministic idempotency key.
 */
class TechReminderScheduler
{
    public function __construct(private OutboxService $outbox) {}

    /**
     * Queue digests for the given phase ('night_before' | 'morning_of').
     *
     * @return array<int, ScheduledMessage>
     */
    public function run(string $phase, ?Carbon $localNow = null): array
    {
        $localNow ??= Timezone::now();
        $isNight = $phase === 'night_before';
        $key = $isNight ? TemplateKey::TechReminderNightBefore : TemplateKey::TechReminderMorningOf;

        // Night-before covers tomorrow's work; morning-of covers today's.
        $targetDate = $isNight ? $localNow->copy()->addDay() : $localNow->copy();

        // Every open job on the tech's calendar for that date — no "booked today"
        // exclusion: the tech wants their full schedule, however late it was added.
        $jobsByTech = Job::query()
            ->with(['customer', 'entity', 'technician'])
            ->open()
            ->whereNull('work_completed_at')
            ->whereNotNull('technician_id')
            ->scheduledOnLocalDate($targetDate)
            ->orderBy('scheduled_at')
            ->get()
            ->groupBy('technician_id');

        $dateKey = $targetDate->format('Y-m-d');
        $created = [];

        foreach ($jobsByTech as $techId => $techJobs) {
            /** @var Employee|null $tech */
            $tech = $techJobs->first()->technician;
            if (! $tech || ! $tech->active) {
                continue;
            }

            $body = $this->buildBody($tech, $techJobs, $targetDate, $isNight);
            $idempotencyKey = "tech:{$techId}:phase:{$phase}:date:{$dateKey}";

            // Queue for immediate dispatch — the every-minute processor sends it.
            $msg = $this->outbox->queueTechDigest($tech, $key, $body, $localNow->copy()->utc(), $idempotencyKey);
            if ($msg) {
                $created[] = $msg;
            }
        }

        return $created;
    }

    /**
     * The digest has no message_templates row — its body is a list assembled
     * here — so it carries its own translation, keyed off the tech's language.
     *
     * @param  Collection<int, Job>  $jobs
     */
    private function buildBody(Employee $tech, Collection $jobs, Carbon $targetDate, bool $isNight): string
    {
        $firstName = Str::of($tech->name)->trim()->before(' ')->toString() ?: $tech->name;
        $spanish = $tech->preferredLanguage() === Language::Spanish;

        $date = $spanish
            ? $targetDate->locale('es')->translatedFormat('D j M')
            : $targetDate->format('D, M j');

        $greeting = match (true) {
            $spanish && $isNight => "¡Buenas noches {$firstName}! Tus trabajos para mañana ({$date}):",
            $spanish => "¡Buenos días {$firstName}! Tus trabajos para hoy ({$date}):",
            $isNight => "Good evening {$firstName}! Your jobs for tomorrow ({$date}):",
            default => "Good morning {$firstName}! Your jobs for today ({$date}):",
        };

        $phoneLabel = $spanish ? 'Telefono' : 'Phone';
        $partLabel = $spanish ? 'Parte #' : 'Part #';
        $whLabel = $spanish ? 'Almacen' : 'Warehouse';

        $blocks = [];
        foreach ($jobs->values() as $i => $job) {
            // Full arrival window ("8am–10am"), or the exact time for exact-time jobs.
            $time = TemplateRenderer::formatWindow($job) ?: Timezone::toDisplay($job->scheduled_at)->format('g:i A');

            $vehicle = trim(implode(' ', array_filter([
                $job->vehicle_year, $job->vehicle_make, $job->vehicle_model,
            ]))) ?: ($job->vehicle_raw ?? '');

            // Full service address, with the city appended when it isn't already in the line.
            $address = $job->fullServiceAddress() ?? '';

            $phone = PhoneNumber::toDisplay($job->customer?->phone_e164) ?? '';
            $partNumber = $job->part_number ?? '';
            $warehouse = $job->warehouseLabel();

            // One block per job: numbered time line, then the same fields as the
            // job confirmation card, so the digest carries everything that card does.
            $detail = array_filter([
                $job->customer_name_snapshot,
                $phone ? "{$phoneLabel}: {$phone}" : null,
                $address ?: null,
                $vehicle ?: null,
                $partNumber ? "{$partLabel}: {$partNumber}" : null,
                $warehouse ? "{$whLabel}: {$warehouse}" : null,
            ]);

            $blocks[] = ($i + 1).'. '.$time."\n".implode("\n", $detail);
        }

        return $greeting."\n\n".implode("\n\n", $blocks);
    }
}
