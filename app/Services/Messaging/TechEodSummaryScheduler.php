<?php

namespace App\Services\Messaging;

use App\Enums\Language;
use App\Enums\TemplateKey;
use App\Models\Employee;
use App\Models\Job;
use App\Models\ScheduledMessage;
use App\Models\TechSettlement;
use App\Models\WarrantyClaim;
use App\Services\JobLifecycle;
use App\Support\Money;
use App\Support\Timezone;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Opt-in nightly pay recap, per technician, per local (Pacific) day. Unlike the
 * TechReminderScheduler digests (planning tools, sent to everyone with jobs on
 * the calendar), this one is a per-tech toggle (employees.eod_summary_texts_enabled)
 * and only sends on a day the tech actually completed something — no completed
 * job AND no paid warranty visit means no text at all. It recaps the same money
 * the End-of-Day Summary page shows (labor + tech-fronted parts + paid warranty
 * visits, netted against cash they collected that day and any balance carried
 * in from before) without waiting for dispatch to run "Mark paid" — this is
 * informational, it settles nothing.
 */
class TechEodSummaryScheduler
{
    public function __construct(private OutboxService $outbox, private JobLifecycle $lifecycle) {}

    /**
     * @return array<int, ScheduledMessage>
     */
    public function run(?Carbon $localNow = null): array
    {
        $localNow ??= Timezone::now();
        $today = $localNow->copy()->startOfDay();
        $dateKey = $today->format('Y-m-d');
        [$start, $end] = Timezone::localDayUtcRange($today);

        $created = [];

        $techs = Employee::query()->active()->eodSummaryEnabled()->get();

        foreach ($techs as $tech) {
            $jobs = $this->lifecycle->techDayCompletedJobs($tech, $today);

            $warrantyVisits = WarrantyClaim::query()
                ->with('job')
                ->where('technician_id', $tech->id)
                ->where('status', WarrantyClaim::STATUS_RESOLVED)
                ->where('tech_pay_cents', '>', 0)
                ->whereBetween('resolved_at', [$start, $end])
                ->orderBy('resolved_at')
                ->get();

            // Nothing was completed today — nothing to summarize, nothing to send.
            if ($jobs->isEmpty() && $warrantyVisits->isEmpty()) {
                continue;
            }

            $idempotencyKey = "tech:{$tech->id}:eod_summary:date:{$dateKey}";
            $body = $this->buildBody($tech, $today, $start, $end, $dateKey, $jobs, $warrantyVisits);

            $msg = $this->outbox->queueTechDigest($tech, TemplateKey::TechEodSummary, $body, $localNow->copy()->utc(), $idempotencyKey);
            if ($msg) {
                $created[] = $msg;
            }
        }

        return $created;
    }

    /**
     * The summary has no message_templates row — its body is assembled here,
     * like the tech reminder digests — so it carries its own translation, keyed
     * off the tech's language.
     *
     * @param  Collection<int, Job>  $jobs
     * @param  Collection<int, WarrantyClaim>  $warrantyVisits
     */
    private function buildBody(
        Employee $tech,
        Carbon $today,
        Carbon $start,
        Carbon $end,
        string $dateKey,
        Collection $jobs,
        Collection $warrantyVisits,
    ): string {
        $spanish = $tech->preferredLanguage() === Language::Spanish;
        $firstName = Str::of($tech->name)->trim()->before(' ')->toString() ?: $tech->name;

        $date = $spanish
            ? $today->locale('es')->translatedFormat('D j M')
            : $today->format('D, M j');

        // Cash collected TODAY (positive collection rows only for the per-job
        // list; reversals net into the running total but never get their own
        // line — nothing to show a tech about a correction we already made).
        $cashRows = TechSettlement::query()
            ->with('job')
            ->where('technician_id', $tech->id)
            ->where('type', 'cash_collected')
            ->where('for_date', $dateKey)
            ->orderBy('id')
            ->get();
        $cashToBalance = $cashRows->where('direction', 'cash_to_balance');
        $cashReversed = $cashRows->where('direction', 'cash_reversed');
        $cashTodayCents = (int) $cashToBalance->sum('amount_cents') - (int) $cashReversed->sum('amount_cents');

        // What they owed us BEFORE today: the balance right before the first
        // settlement row of the local day, or (no rows today) their current
        // running balance.
        $firstSettlementToday = TechSettlement::query()
            ->where('technician_id', $tech->id)
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('id')
            ->first();
        $startOfDayBalanceCents = $firstSettlementToday
            ? (int) $firstSettlementToday->balance_before_cents
            : $tech->cashOwedCents();

        $laborCents = (int) $jobs->sum(fn (Job $job) => $job->expected_tech_pay_cents);
        $partsCents = (int) $jobs->sum(fn (Job $job) => $job->tech_parts_reimbursement_cents);
        $warrantyCents = (int) $warrantyVisits->sum('tech_pay_cents');
        $totalCents = $laborCents + $partsCents + $warrantyCents;
        $netCents = $totalCents - $cashTodayCents;

        $lines = [$spanish
            ? "¡Hola {$firstName}! Tu resumen de fin de día para {$date}:"
            : "Hi {$firstName}! Your end of day summary for {$date}:"];

        if ($startOfDayBalanceCents > 0) {
            $lines[] = '';
            $lines[] = $spanish
                ? 'Saldo pendiente de antes: '.Money::format($startOfDayBalanceCents).' (efectivo cobrado antes, aún no entregado)'
                : 'Balance owed from before: '.Money::format($startOfDayBalanceCents).' (cash collected earlier, not yet turned in)';
        }

        $lines[] = '';
        $lines[] = $spanish ? 'Trabajos completados:' : 'Jobs completed:';

        foreach ($jobs->values() as $i => $job) {
            $lines[] = ($i + 1).'. '.$this->vehicleOrJobLabel($job).' - '.Money::format($job->expected_tech_pay_cents);
        }

        foreach ($warrantyVisits as $claim) {
            $vehicle = $claim->job ? $this->vehicleOrJobLabel($claim->job) : '';
            $lines[] = ($spanish ? 'Visita de garantía: ' : 'Warranty visit: ').$vehicle.' - '.Money::format((int) $claim->tech_pay_cents);
        }

        if ($partsCents > 0) {
            $lines[] = ($spanish ? 'Piezas que pagaste (reembolsado): ' : 'Parts you paid for (reimbursed): ').Money::format($partsCents);
        }

        $lines[] = ($spanish ? 'Total: ' : 'Total: ').Money::format($totalCents);

        if ($cashToBalance->isNotEmpty()) {
            $lines[] = '';
            $lines[] = $spanish ? 'Efectivo cobrado:' : 'Cash collected:';
            foreach ($cashToBalance as $row) {
                $vehicle = $row->job ? $this->vehicleOrJobLabel($row->job) : '';
                $lines[] = '- '.Money::format((int) $row->amount_cents).' ('.$vehicle.')';
            }
            $lines[] = ($spanish ? 'Total en efectivo: ' : 'Cash total: ').Money::format($cashTodayCents);
        }

        $lines[] = '';
        $lines[] = ($spanish ? 'Pago neto de hoy: ' : 'Net pay for today: ').$this->formatSigned($netCents);

        $closing = $this->closingLine($spanish, $startOfDayBalanceCents, $netCents);
        if ($closing !== null) {
            $lines[] = '';
            $lines[] = $closing;
        }

        return implode("\n", $lines);
    }

    /** How this day's math against a prior balance settles, spelled out for the tech. */
    private function closingLine(bool $spanish, int $startOfDayBalanceCents, int $netCents): ?string
    {
        if ($startOfDayBalanceCents > 0) {
            $expectedPayout = max(0, $netCents - $startOfDayBalanceCents);
            $remainingBalance = max(0, $startOfDayBalanceCents - max(0, $netCents));
            $bal = Money::format($startOfDayBalanceCents);

            if ($expectedPayout > 0) {
                return $spanish
                    ? "Se descontará tu saldo pendiente de {$bal}, así que tu pago esperado es ".Money::format($expectedPayout).'.'
                    : "Your balance owed of {$bal} will be deducted, so your expected payout is ".Money::format($expectedPayout).'.';
            }

            if ($remainingBalance > 0) {
                $remaining = Money::format($remainingBalance);

                return $spanish
                    ? "Tu pago neto cubre parte de tu saldo pendiente; todavía debes {$remaining} en efectivo."
                    : "Your net pay covers part of your balance owed; you still owe {$remaining} in cash.";
            }

            return $spanish
                ? "Se descontará tu saldo pendiente de {$bal}, quedando en cero."
                : "Your balance owed of {$bal} will be deducted, clearing your balance.";
        }

        if ($netCents < 0) {
            $surplus = Money::format(abs($netCents));

            return $spanish
                ? "Cobraste {$surplus} más en efectivo que tu pago de hoy - eso queda en tu saldo pendiente por entregar."
                : "You collected {$surplus} more in cash than today's pay - that stays on your balance to turn in.";
        }

        return null;
    }

    /** Year/make/model (or raw string); falls back to the job number when blank. */
    private function vehicleOrJobLabel(Job $job): string
    {
        $label = $job->vehicleLabel();

        return $label !== '' ? $label : (string) ($job->job_number ?? '');
    }

    /** "$1,234.56" for a non-negative amount, "-$1,234.56" for a negative one. */
    private function formatSigned(int $cents): string
    {
        return $cents < 0 ? '-'.Money::format(abs($cents)) : Money::format($cents);
    }
}
