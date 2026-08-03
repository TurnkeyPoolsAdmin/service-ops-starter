<?php

namespace App\Console\Commands;

use App\Enums\IssueType;
use App\Enums\Language;
use App\Models\BankTransaction;
use App\Models\Issue;
use App\Models\Job;
use App\Models\MessageTemplate;
use App\Models\VendorDocument;
use App\Models\VendorDocumentLine;
use App\Services\Vendors\ChargeResolution;
use App\Services\Vendors\Igc\IgcNetter;
use App\Services\Vendors\VendorChargeReconciler;
use App\Support\Money;
use App\Support\Timezone;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Materializes the RECOMPUTED (condition-shaped) issues onto the super-admin
 * Issues queue, then heals them:
 *
 *  - JobCompletedUnpaid — work finished on a PRIOR local day but still not paid
 *    in full ("it's now the next day and the money's still on the table").
 *  - PartNotOrdered — a job scheduled for TODAY (local) whose glass part hasn't
 *    been ordered yet.
 *  - XproUncollected — Xpro-collected money not collected back by its deadline
 *    (the Wednesday after the Friday it was first owed).
 *  - TranslationStale — a Spanish template row whose English sibling was edited
 *    more recently (subject is a MessageTemplate, not a Job — the only one here
 *    that isn't).
 *  - MygrantChargeMismatch — a Mygrant card charge still short/over/ambiguous
 *    against its open documents (subject is the BankTransaction).
 *  - MygrantLineUnmatched — a billed Mygrant order-history line with no job
 *    decided 3+ days after it billed (subject is the VendorDocumentLine).
 *  - IgcCreditUnmatched — an IGC return with no original invoice found even
 *    after netting's +/-30 day widened search (subject is the credit
 *    VendorDocument). Never observed on the real export; see IgcNetter.
 *
 * Unlike the deleted/cancelled/mismatch issues (raised once at the moment the
 * thing happens), these are conditions that come and go with the clock and with
 * the dispatcher's actions. So each run RECOMPUTES the live set: raise() for jobs
 * now matching (idempotent — never duplicates an open row) and clearOpenFor() for
 * open issues whose job no longer matches (payment recorded, part ordered, day
 * rolled past, job canceled/deleted). The Issue row is effectively a materialized
 * view of the condition, so a super admin can also manually Clear one and it just
 * comes back next sweep if still true.
 *
 * Runs hourly (see routes/console.php). No external calls, no send — safe to run
 * often; a clean board is a no-op.
 */
class SweepJobIssues extends Command
{
    protected $signature = 'issues:sweep';

    protected $description = 'Raise/clear the time-relative Issues (completed-but-unpaid next day, part not ordered for a today job, Xpro money overdue to collect back).';

    public function handle(): int
    {
        $unpaid = $this->syncType(IssueType::JobCompletedUnpaid, $this->completedUnpaidNextDay());
        $unordered = $this->syncType(IssueType::PartNotOrdered, $this->partNotOrderedToday());
        $xpro = $this->syncType(IssueType::XproUncollected, $this->xproCollectionOverdue());
        $partGlass = $this->healPartGlassMismatch();
        $stale = $this->syncStaleTranslations();
        $mygrantCharges = $this->syncMygrantChargeMismatch();
        $mygrantLines = $this->syncMygrantLineUnmatched();
        $igcCredits = $this->syncIgcCreditUnmatched();

        $this->info("Swept job issues: completed-unpaid {$unpaid} open, part-not-ordered {$unordered} open, "
            ."part-glass healed {$partGlass}, xpro-overdue {$xpro} open, spanish-review {$stale} open, "
            ."mygrant-charge {$mygrantCharges} open, mygrant-line {$mygrantLines} open, igc-credit {$igcCredits} open.");

        return self::SUCCESS;
    }

    /**
     * Reconcile the OPEN issues of one type against the jobs that currently
     * match: raise for the new ones, clear for the resolved ones. Returns the
     * number of jobs matching (= open issues of this type afterwards).
     */
    private function syncType(IssueType $type, Collection $jobs): int
    {
        $matchingIds = $jobs->pluck('id')->all();

        foreach ($jobs as $job) {
            Issue::raise($type, $job, $this->snapshot($type, $job));
        }

        // Heal any open issue of this type whose job dropped out of the set.
        $stale = Issue::query()->open()
            ->where('type', $type->value)
            ->where('subject_type', (new Job)->getMorphClass())
            ->when($matchingIds !== [], fn ($q) => $q->whereNotIn('subject_id', $matchingIds))
            ->pluck('subject_id');

        foreach ($stale as $jobId) {
            if ($job = Job::withTrashed()->find($jobId)) {
                Issue::clearOpenFor($job, $type);
            }
        }

        return count($matchingIds);
    }

    /**
     * PartGlassMismatch is the one type here this sweep only HEALS, never
     * raises. It is event-shaped, not condition-shaped: the fact worth reviewing
     * is that a dispatcher CHOSE to schedule over the warning, so it's raised at
     * that moment (JobLifecycle::overridePartGlassMismatch) and a manager's
     * "Clear" has to stick. What the sweep does is take it off the queue once
     * somebody corrects the part number or the glass position — the override no
     * longer excuses anything, because there's nothing left to excuse.
     *
     * Returns how many were healed this run.
     */
    private function healPartGlassMismatch(): int
    {
        $open = Issue::query()->open()
            ->where('type', IssueType::PartGlassMismatch->value)
            ->where('subject_type', (new Job)->getMorphClass())
            ->pluck('subject_id');

        $healed = 0;
        foreach ($open as $jobId) {
            $job = Job::withTrashed()->find($jobId);

            if ($job === null || $job->hasOverriddenPartGlassMismatch()) {
                continue;
            }

            Issue::clearOpenFor($job, IssueType::PartGlassMismatch);
            $healed++;
        }

        return $healed;
    }

    /**
     * The one non-Job condition here: a Spanish template row whose English
     * sibling was edited more recently, so a native speaker should re-check the
     * translation. Subject is the SPANISH MessageTemplate row (the thing that
     * needs the work), and staleness is derived from updated_at — so saving the
     * Spanish body, or the "Spanish still fine" action, heals it next sweep.
     */
    private function syncStaleTranslations(): int
    {
        $stale = MessageTemplate::staleTranslations();

        foreach ($stale as $template) {
            Issue::raise(IssueType::TranslationStale, $template, $this->translationSnapshot($template));
        }

        $staleIds = $stale->pluck('id')->all();

        $resolved = Issue::query()->open()
            ->where('type', IssueType::TranslationStale->value)
            ->where('subject_type', (new MessageTemplate)->getMorphClass())
            ->when($staleIds !== [], fn ($q) => $q->whereNotIn('subject_id', $staleIds))
            ->pluck('subject_id');

        foreach ($resolved as $templateId) {
            if ($template = MessageTemplate::find($templateId)) {
                Issue::clearOpenFor($template, IssueType::TranslationStale);
            }
        }

        return $stale->count();
    }

    /**
     * Mygrant card charges still short/over/ambiguous against their open
     * documents — recomputed live via VendorChargeReconciler::resolve() (the
     * same idempotent call the daily mygrant:reconcile pass makes, so a charge
     * that now ties out simply drops off this run rather than needing a
     * separate "un-raise" path).
     */
    private function syncMygrantChargeMismatch(): int
    {
        $reconciler = app(VendorChargeReconciler::class);

        $needsAttention = $reconciler->candidateCharges()
            ->map(fn (BankTransaction $txn) => $reconciler->resolve($txn))
            ->filter(fn (ChargeResolution $r) => $r->needsAttention())
            ->values();

        foreach ($needsAttention as $resolution) {
            Issue::raise(IssueType::MygrantChargeMismatch, $resolution->transaction, $this->mygrantChargeSnapshot($resolution));
        }

        $matchingIds = $needsAttention->map(fn (ChargeResolution $r) => $r->transaction->id)->all();

        $stale = Issue::query()->open()
            ->where('type', IssueType::MygrantChargeMismatch->value)
            ->where('subject_type', (new BankTransaction)->getMorphClass())
            ->when($matchingIds !== [], fn ($q) => $q->whereNotIn('subject_id', $matchingIds))
            ->pluck('subject_id');

        foreach ($stale as $txnId) {
            if ($txn = BankTransaction::find($txnId)) {
                Issue::clearOpenFor($txn, IssueType::MygrantChargeMismatch);
            }
        }

        return count($matchingIds);
    }

    private function mygrantChargeSnapshot(ChargeResolution $r): array
    {
        return [
            'txn_description' => $r->transaction->name,
            'txn_date' => $r->transaction->date,
            'charge_cents' => $r->chargeCents,
            'full_sum_cents' => $r->fullSumCents,
            'shortfall_cents' => $r->shortfallCents,
            'overage_cents' => $r->overageCents,
            'state' => $r->state,
        ];
    }

    /**
     * Billed Mygrant lines still needing a job decision (unmatched OR
     * suggested — VendorDocumentLine::scopeNeedsJob) 3+ days after billing —
     * a part bought against no apparent work order is worth a second look.
     */
    private function syncMygrantLineUnmatched(): int
    {
        $cutoff = now()->subDays(3);

        $lines = VendorDocumentLine::query()
            ->needsJob()
            ->where('created_at', '<=', $cutoff)
            ->with('warehouse')
            ->get();

        foreach ($lines as $line) {
            Issue::raise(IssueType::MygrantLineUnmatched, $line, $this->mygrantLineSnapshot($line));
        }

        $matchingIds = $lines->pluck('id')->all();

        $stale = Issue::query()->open()
            ->where('type', IssueType::MygrantLineUnmatched->value)
            ->where('subject_type', (new VendorDocumentLine)->getMorphClass())
            ->when($matchingIds !== [], fn ($q) => $q->whereNotIn('subject_id', $matchingIds))
            ->pluck('subject_id');

        foreach ($stale as $lineId) {
            if ($line = VendorDocumentLine::find($lineId)) {
                Issue::clearOpenFor($line, IssueType::MygrantLineUnmatched);
            }
        }

        return count($matchingIds);
    }

    private function mygrantLineSnapshot(VendorDocumentLine $line): array
    {
        return [
            'order_line' => $line->order_line,
            'part_number' => $line->part_number,
            'part_description' => $line->part_description,
            'amount' => Money::format((int) $line->line_total_cents),
            'order_date' => $line->confirmed_date ?: $line->order_date,
            'warehouse' => $line->warehouse?->name,
        ];
    }

    /**
     * IGC returns still unpaired to an original invoice — I5 / rules R3.4.
     * Recomputed live via IgcNetter::unpairedCredits() (the same call
     * igc:reconcile makes), so a credit that finds its original on a later
     * upload simply drops off this run.
     */
    private function syncIgcCreditUnmatched(): int
    {
        $unpaired = app(IgcNetter::class)->unpairedCredits();

        foreach ($unpaired as $credit) {
            Issue::raise(IssueType::IgcCreditUnmatched, $credit, $this->igcCreditSnapshot($credit));
        }

        $matchingIds = $unpaired->pluck('id')->all();

        $stale = Issue::query()->open()
            ->where('type', IssueType::IgcCreditUnmatched->value)
            ->where('subject_type', (new VendorDocument)->getMorphClass())
            ->when($matchingIds !== [], fn ($q) => $q->whereNotIn('subject_id', $matchingIds))
            ->pluck('subject_id');

        foreach ($stale as $documentId) {
            if ($document = VendorDocument::find($documentId)) {
                Issue::clearOpenFor($document, IssueType::IgcCreditUnmatched);
            }
        }

        return count($matchingIds);
    }

    private function igcCreditSnapshot(VendorDocument $credit): array
    {
        return [
            'document_number' => $credit->document_number,
            'amount' => Money::format(abs((int) $credit->net_amount_cents)),
            'document_date' => $credit->document_date,
        ];
    }

    /**
     * Both current bodies, so the reviewer can compare them in the issue's View
     * modal without leaving the queue. Deliberately NOT a diff — these are short
     * SMS bodies and a stored before/after snapshot would need its own column.
     */
    private function translationSnapshot(MessageTemplate $spanish): array
    {
        $english = $spanish->sibling(Language::English);

        return [
            'template' => $spanish->name,
            'template_key' => $spanish->key,
            'english_edited' => Timezone::toDisplay($english?->updated_at)?->format('M j, Y g:ia'),
            'spanish_reviewed' => Timezone::toDisplay($spanish->updated_at)?->format('M j, Y g:ia'),
            'english_body' => $english?->body,
            'spanish_body' => $spanish->body,
        ];
    }

    /**
     * Work complete + not fully paid, where completion landed on a local day
     * BEFORE today — i.e. it's carried into (at least) the next day still owing.
     * A job finished earlier today is not yet "the next day" and is excluded.
     */
    private function completedUnpaidNextDay(): Collection
    {
        [$startOfTodayUtc] = Timezone::localDayUtcRange(Timezone::now());

        return Job::query()
            ->awaitingPayment()
            ->where('work_completed_at', '<', $startOfTodayUtc)
            ->with(['entity', 'technician'])
            ->get();
    }

    /** Jobs scheduled for today (local) whose glass part hasn't been ordered. */
    private function partNotOrderedToday(): Collection
    {
        return Job::query()
            ->partsToOrder()
            ->scheduledToday()
            ->with(['entity', 'technician'])
            ->get();
    }

    /**
     * Xpro-collected money we still haven't collected back, past its deadline (the
     * Wednesday after the Friday it was first owed — Job::xproCollectionDueAt). The
     * date math is per-job, so the SQL just narrows to the owed set and the exact
     * "overdue?" check runs in PHP.
     */
    private function xproCollectionOverdue(): Collection
    {
        return Job::owedFromXpro()
            ->with(['entity', 'technician', 'customer', 'payments'])
            ->get()
            ->filter(fn (Job $job) => $job->isXproCollectionOverdue())
            ->values();
    }

    private function snapshot(IssueType $type, Job $job): array
    {
        $base = [
            'job_number' => $job->job_number,
            'customer_name' => $job->customer_name_snapshot,
            'entity' => $job->entity?->name,
            'technician' => $job->technician?->name,
            'quote_cents' => $job->quote_amount_cents,
        ];

        return match ($type) {
            IssueType::JobCompletedUnpaid => [
                ...$base,
                'amount_collected_cents' => $job->amount_collected_cents,
                'balance_due_cents' => $job->balanceDueCents(),
                'completed_on' => Timezone::toDisplay($job->work_completed_at)?->format('Y-m-d'),
            ],
            IssueType::PartNotOrdered => [
                ...$base,
                'glass_position' => $job->glass_position,
                'part_number' => $job->part_number,
                'scheduled_at' => $job->scheduled_at?->toIso8601String(),
            ],
            IssueType::XproUncollected => [
                ...$base,
                'xpro_owed_cents' => $job->xproOwedCents(),
                'xpro_method' => $job->xproMethodsLabel(),
                'owed_since' => Timezone::toDisplay($job->xproOwedSince())?->format('Y-m-d'),
                'due_by' => $job->xproCollectionDueAt()?->format('Y-m-d'),
            ],
            default => $base,
        };
    }
}
