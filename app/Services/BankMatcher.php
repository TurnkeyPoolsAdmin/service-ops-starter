<?php

namespace App\Services;

use App\Enums\BankMatchKind;
use App\Enums\JobFunction;
use App\Enums\PaymentMethod;
use App\Models\BankTransaction;
use App\Models\BankTransactionMatch;
use App\Models\Employee;
use App\Models\Job;
use App\Models\JobPayment;
use App\Models\TechSettlement;
use App\Services\Vendors\Igc\IgcChargeReconciler;
use App\Services\Vendors\VendorChargeReconciler;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Auto-suggests what each Chase transaction was, so the weekly reconciliation is
 * mostly one-click confirms. Rules, in order, per unmatched posted transaction:
 *
 *   1. Deterministic misc (Google Ads, card autopay, bank fees) → matched
 *      outright, no human needed (config glass.bank_matching.auto_misc) — unless
 *      the category has a cap (glass.bank_matching.auto_misc_caps, e.g. Google
 *      Ads at $500) and the amount exceeds it, in which case it's still
 *      suggested as that category but waits for a human to confirm.
 *   2. Money IN  → a job_payments LINE (a job can have several — deposit + balance,
 *      possibly different methods/days) paid by a bank-matchable method with the
 *      exact amount within ±N days → suggested. Matching against the line, not
 *      the job's summary total, is what lets a partially-paid job's deposit and
 *      balance each find their own deposit.
 *   3. Money OUT hitting a known glass vendor → a job whose NET part cost
 *      (part_cost_cents minus whatever the tech fronted and gets reimbursed for
 *      separately) equals the charge → suggested as a parts purchase.
 *   4. Other money OUT → an End-of-Day (or warranty) tech payout with the exact
 *      amount settled the SAME (Pacific) day → suggested. (Cash a tech collected
 *      already reduced the payout before it was zelled, so the settlement amount
 *      IS the bank amount.) Off-amount, off-day, or undone settlements never
 *      auto-match — the ledger's manual Match modal is where a same-day
 *      off-amount payout gets matched with a written explanation instead.
 *
 * A suggestion is only made when it's unambiguous: exactly one candidate, or the
 * candidate's name (customer/tech) appears in the bank description. Ambiguous
 * transactions stay unmatched for the human. Each line/job/settlement is
 * suggested at most once — a record already linked (suggested or confirmed) is
 * off the table.
 */
class BankMatcher
{
    public function __construct(
        private readonly VendorChargeReconciler $vendorChargeReconciler,
        private readonly IgcChargeReconciler $igcChargeReconciler,
    ) {}

    /** Run over everything needing review; returns [auto => n, suggested => n]. */
    public function run(): array
    {
        $auto = 0;
        $suggested = 0;

        // Materialised up front (not chunked) because matchOne() flips rows out
        // of this filter as it goes, which would make chunk pagination skip rows.
        $pending = BankTransaction::query()
            ->where('pending', false)
            ->where('match_status', BankTransaction::STATUS_UNMATCHED)
            ->orderBy('date')
            ->get();

        foreach ($pending as $txn) {
            match ($this->matchOne($txn)) {
                BankTransaction::STATUS_MATCHED => $auto++,
                BankTransaction::STATUS_SUGGESTED => $suggested++,
                default => null,
            };
        }

        return ['auto' => $auto, 'suggested' => $suggested];
    }

    /** Try all rules on one transaction; returns the resulting status or null. */
    public function matchOne(BankTransaction $txn): ?string
    {
        if ($category = $this->autoMiscCategory($txn)) {
            $confirmed = $this->withinAutoMiscCap($category, $txn);
            $this->link($txn, BankMatchKind::Misc, miscCategory: $category, confirmed: $confirmed);

            return $confirmed ? BankTransaction::STATUS_MATCHED : BankTransaction::STATUS_SUGGESTED;
        }

        if ($txn->amount_cents < 0) {
            $line = $this->findCustomerPayment($txn);
            if ($line) {
                $this->link($txn, BankMatchKind::JobPayment, job: $line->job, jobPayment: $line);

                return BankTransaction::STATUS_SUGGESTED;
            }

            return null;
        }

        if ($this->looksLikePartsVendor($txn)) {
            // A Mygrant charge is an aggregated weekly total across several
            // documents, never a single job's part cost — route it to the
            // dedicated document-level reconciler FIRST so it doesn't sit
            // unmatched forever waiting for findPartPurchase's one-job-exact-
            // amount test to (never) pass. looksLikePartsVendor still returning
            // true for Mygrant is what keeps this from falling through to
            // tech-payout matching below when the reconciler finds nothing.
            if ($this->vendorChargeReconciler->isMygrantCharge($txn)) {
                $resolution = $this->vendorChargeReconciler->resolve($txn);

                return match ($resolution->state) {
                    'matched' => BankTransaction::STATUS_MATCHED,
                    'suggested' => BankTransaction::STATUS_SUGGESTED,
                    default => null,
                };
            }

            // Same reasoning as Mygrant above — an IGC charge is a per-DATE
            // aggregate of several invoices' payments (see IgcChargeReconciler's
            // §0/C1 doc comment), never a single job's part cost, so it must
            // never fall through to findPartPurchase's one-job-exact-amount test.
            if ($this->igcChargeReconciler->isIgcCharge($txn)) {
                $resolution = $this->igcChargeReconciler->resolve($txn);

                return match ($resolution->state) {
                    'matched' => BankTransaction::STATUS_MATCHED,
                    'suggested' => BankTransaction::STATUS_SUGGESTED,
                    default => null,
                };
            }

            $job = $this->findPartPurchase($txn);
            if ($job) {
                $this->link($txn, BankMatchKind::PartPurchase, job: $job);

                return BankTransaction::STATUS_SUGGESTED;
            }

            // A vendor charge with no clean job candidate stays unmatched, but we
            // don't fall through to tech-payout matching — it's clearly parts.
            return null;
        }

        // Outgoing Zelle to an owner (Alex / David) is a dividend, not a tech
        // payout — deterministic on the recipient name, so auto-matched like the
        // misc keywords (no review), the recipient key stashed in misc_category.
        if ($recipient = $this->dividendRecipient($txn)) {
            $this->link($txn, BankMatchKind::Dividend, miscCategory: $recipient, confirmed: true);

            return BankTransaction::STATUS_MATCHED;
        }

        $settlement = $this->findTechPayout($txn);
        if ($settlement) {
            $this->link($txn, BankMatchKind::TechPayout, settlement: $settlement);

            return BankTransaction::STATUS_SUGGESTED;
        }

        return null;
    }

    /**
     * The configured owner an outgoing Zelle was a dividend to, by name — or null.
     * Same shape as techFor(): the description must read as a Zelle and contain
     * every token of the recipient's name; only an unambiguous single match wins.
     * Returns the recipient KEY (e.g. 'pat_owner'), stored on the match.
     */
    public function dividendRecipient(BankTransaction $txn): ?string
    {
        if ($txn->amount_cents <= 0) {
            return null;
        }

        $haystack = mb_strtolower($txn->name.' '.($txn->merchant_name ?? ''));

        if (! str_contains($haystack, 'zelle') || $this->looksLikePartsVendor($txn)) {
            return null;
        }

        $hits = [];
        foreach (BankMatchKind::dividendRecipients() as $key => $name) {
            $tokens = array_filter(
                preg_split('/\s+/', mb_strtolower(trim((string) $name))) ?: [],
                fn (string $t) => mb_strlen($t) >= 2,
            );

            if ($tokens === []) {
                continue;
            }

            $allPresent = true;
            foreach ($tokens as $token) {
                if (! $this->tokenInDescription($token, $haystack)) {
                    $allPresent = false;
                    break;
                }
            }

            if ($allPresent) {
                $hits[] = $key;
            }
        }

        return count($hits) === 1 ? $hits[0] : null;
    }

    /**
     * The one technician an outgoing Zelle transaction belongs to, by name —
     * used both by findTechPayout() and by the ledger UI (to lock the manual
     * "Match" form to a tech payout). Money-in and parts-vendor charges never
     * resolve to a tech, even though matchOne() already routes around this
     * method for those cases.
     */
    public function techFor(BankTransaction $txn): ?Employee
    {
        if ($txn->amount_cents <= 0) {
            return null;
        }

        $haystack = mb_strtolower($txn->name.' '.($txn->merchant_name ?? ''));

        if (! str_contains($haystack, 'zelle') || $this->looksLikePartsVendor($txn)) {
            return null;
        }

        $matches = Employee::query()
            ->withJobFunction(JobFunction::Technician)
            ->get()
            ->filter(function (Employee $tech) use ($haystack) {
                $tokens = array_filter(
                    preg_split('/\s+/', mb_strtolower(trim($tech->zelleMatchName()))) ?: [],
                    fn (string $t) => mb_strlen($t) >= 2,
                );

                if ($tokens === []) {
                    return false;
                }

                foreach ($tokens as $token) {
                    if (! $this->tokenInDescription($token, $haystack)) {
                        return false;
                    }
                }

                return true;
            });

        return $matches->count() === 1 ? $matches->first() : null;
    }

    // ---- Rules ----

    private function autoMiscCategory(BankTransaction $txn): ?string
    {
        $haystack = mb_strtolower($txn->name.' '.($txn->merchant_name ?? ''));

        foreach ((array) config('glass.bank_matching.auto_misc', []) as $category => $needles) {
            foreach ((array) $needles as $needle) {
                if (str_contains($haystack, mb_strtolower($needle))) {
                    return $category;
                }
            }
        }

        return null;
    }

    /** False when the category has a configured cap and the amount exceeds it — needs a human. */
    private function withinAutoMiscCap(string $category, BankTransaction $txn): bool
    {
        $cap = config("glass.bank_matching.auto_misc_caps.$category");

        return $cap === null || abs($txn->amount_cents) <= $cap;
    }

    /**
     * Incoming deposit → the one payment LINE it settles. A job can carry several
     * lines (deposit + balance, possibly different methods/days), so matching
     * against the job's summary total would either miss a partial payment or
     * mis-credit the wrong installment — this matches against the LINE itself.
     * First by amount (within ±$tolerance of the line's REMAINING, unclaimed
     * balance — see remainingCentsForLine()), disambiguated by the customer
     * name in the description; if that finds nothing, by the customer's first
     * name alone (amount ignored — the human confirms, and a line matched to
     * an off-amount deposit surfaces as a "transaction amount mismatch").
     *
     * ⚠️ 2026-07-30: this NEVER takes disambiguate()'s single-candidate free
     * pass, even when it's the only candidate in the window — amount+date
     * proximity alone isn't enough signal to safely attribute someone's
     * money to them. Two real customer-payment matches (both single-
     * candidate, both auto-accepted on amount+date alone under the old
     * behavior) turned out to be completely unrelated customers — same
     * ballpark amount, same window, zero name correspondence. A line
     * already partially claimed (a customer who split one job's total
     * across two transfers) has the same risk for the same reason: "has
     * room left" replacing "is completely untouched" as the bar for
     * offerability doesn't change that amount+date alone was never a safe
     * enough signal on its own, for either case.
     */
    private function findCustomerPayment(BankTransaction $txn): ?JobPayment
    {
        $deposit = abs($txn->amount_cents);
        $tolerance = (int) config('glass.bank_matching.amount_tolerance_cents', 500);
        $days = (int) config('glass.bank_matching.payment_window_days', 5);
        [$start, $end] = $this->windowAround($txn->matchDate(), $days);

        $windowed = $this->unmatchedPaymentLines()
            ->whereBetween('collected_at', [$start, $end])
            ->with('job')
            ->get();

        $remaining = $windowed->mapWithKeys(fn (JobPayment $l) => [$l->id => $this->remainingCentsForLine($l)]);

        $byAmount = $windowed->filter(fn (JobPayment $l) => abs($remaining[$l->id] - $deposit) <= $tolerance);

        if ($line = $this->disambiguate($byAmount, $txn, fn (JobPayment $p) => $p->job?->customer_name_snapshot, requireNameMatch: true)) {
            return $line;
        }

        return $this->findByCustomerName($txn);
    }

    /** A deposit whose amount didn't match → the one recent payment line whose customer's first name is in the description. */
    private function findByCustomerName(BankTransaction $txn): ?JobPayment
    {
        $days = (int) config('glass.bank_matching.name_window_days', 14);
        [$start, $end] = $this->windowAround($txn->matchDate(), $days);
        $description = mb_strtolower($txn->name);

        $candidates = $this->unmatchedPaymentLines()
            ->whereBetween('collected_at', [$start, $end])
            ->with('job')
            ->get()
            ->filter(function (JobPayment $line) use ($description) {
                $first = mb_strtolower((string) (preg_split('/\s+/', trim((string) $line->job?->customer_name_snapshot))[0] ?? ''));

                return mb_strlen($first) >= 3 && $this->tokenInDescription($first, $description);
            });

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /**
     * Payment methods that can ever reconcile to a Chase transaction. Cash never
     * hits the bank (the tech collects it in person); Xpro-collected methods
     * settle with the Xpro team, not Chase (see "Owed from Xpro").
     */
    public static function bankMatchableMethods(): array
    {
        return [PaymentMethod::Zelle->value, PaymentMethod::Venmo->value, PaymentMethod::Stripe->value, PaymentMethod::Check->value];
    }

    /**
     * Payment lines that still have unclaimed room: SUM of every existing
     * job_payment match's bank-transaction amount against the line is still
     * short of the line's own amount_cents (matched or suggested — a
     * suggestion already claims the money the same way a confirmed match
     * does). Lets a $798 line paid via two separate $399 Zelle transfers
     * offer its second $399 of room instead of being excluded outright the
     * instant the FIRST transfer links to it (2026-07-30 — previously a
     * binary "has any match at all" exclusion, which made a second real
     * transaction on the same line permanently unmatchable).
     *
     * ALSO excludes any line whose job carries an older, job-LEVEL job_payment
     * match (one made before job_payment_id existed) — otherwise a legacy
     * whole-job match and a new line-level one could both claim the same
     * money. Shared by the auto-matcher and the ledger's manual Match modal
     * so the two never disagree about what's still up for grabs.
     *
     * $excluding lets the manual Match modal recompute a transaction's OWN
     * prior claim out of the tally when its Match action is reopened on an
     * already-suggested transaction — the human is about to REPLACE that
     * claim (matchAction's action() deletes it first), not add to it, so it
     * shouldn't count against the line's remaining room while they're
     * choosing its replacement. The auto-matcher never needs this (it only
     * ever processes transactions with no existing match at all).
     */
    public function unmatchedPaymentLines(?BankTransaction $excluding = null): Builder
    {
        $legacyJobIds = BankTransactionMatch::query()
            ->where('kind', BankMatchKind::JobPayment->value)
            ->whereNull('job_payment_id')
            ->whereNotNull('job_id')
            ->pluck('job_id');

        return JobPayment::query()
            ->whereIn('method', self::bankMatchableMethods())
            ->when($legacyJobIds->isNotEmpty(), fn (Builder $q) => $q->whereNotIn('job_id', $legacyJobIds))
            ->whereNotIn('id', $this->fullyClaimedLineIds($excluding));
    }

    /** job_payment lines whose existing matches already sum to at least the line's own amount — no room left. */
    private function fullyClaimedLineIds(?BankTransaction $excluding = null): Collection
    {
        return BankTransactionMatch::query()
            ->join('bank_transactions', 'bank_transactions.id', '=', 'bank_transaction_matches.bank_transaction_id')
            ->join('job_payments', 'job_payments.id', '=', 'bank_transaction_matches.job_payment_id')
            ->where('bank_transaction_matches.kind', BankMatchKind::JobPayment->value)
            ->whereNotNull('bank_transaction_matches.job_payment_id')
            ->when($excluding, fn (Builder $q) => $q->where('bank_transaction_matches.bank_transaction_id', '!=', $excluding->id))
            ->groupBy('bank_transaction_matches.job_payment_id', 'job_payments.amount_cents')
            ->havingRaw('sum(abs(bank_transactions.amount_cents)) >= job_payments.amount_cents')
            ->pluck('bank_transaction_matches.job_payment_id');
    }

    /**
     * Cents of a payment line's own amount not yet claimed by any existing
     * job_payment match (matched or suggested, same "already spoken for"
     * definition as unmatchedPaymentLines()). Never negative — an over-
     * claimed line floors at 0 rather than reporting a negative "room". See
     * unmatchedPaymentLines() for what $excluding is for.
     */
    public function remainingCentsForLine(JobPayment $line, ?BankTransaction $excluding = null): int
    {
        $claimed = (int) BankTransactionMatch::query()
            ->join('bank_transactions', 'bank_transactions.id', '=', 'bank_transaction_matches.bank_transaction_id')
            ->where('bank_transaction_matches.kind', BankMatchKind::JobPayment->value)
            ->where('bank_transaction_matches.job_payment_id', $line->id)
            ->when($excluding, fn (Builder $q) => $q->where('bank_transaction_matches.bank_transaction_id', '!=', $excluding->id))
            ->selectRaw('coalesce(sum(abs(bank_transactions.amount_cents)), 0) as claimed')
            ->value('claimed');

        return max(0, (int) $line->amount_cents - $claimed);
    }

    private function looksLikePartsVendor(BankTransaction $txn): bool
    {
        $haystack = mb_strtolower($txn->name.' '.($txn->merchant_name ?? ''));

        foreach ((array) config('glass.bank_matching.parts_vendors', []) as $vendor) {
            if (str_contains($haystack, mb_strtolower($vendor))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Vendor charge → the one recent job whose NET part cost (what actually left
     * OUR account — part_cost_cents minus whatever the tech fronted and gets
     * reimbursed for separately) equals the charge. A raw part_cost_cents match
     * would demand the whole part price show up at Chase even when the tech
     * already paid part of it out of pocket. Written as a portable SQL
     * expression (SQLite + MySQL) rather than pulling every job into PHP.
     *
     * Vendor-to-warehouse disambiguation (preferring a candidate whose warehouse
     * matches the description's vendor keyword) was left out here — the ledger's
     * manual Match modal already surfaces that as a warehouse filter for a human
     * to pick, and auto-matching still requires exactly one net-amount candidate.
     */
    private function findPartPurchase(BankTransaction $txn): ?Job
    {
        $days = (int) config('glass.bank_matching.parts_window_days', 30);
        [$start, $end] = $this->windowAround($txn->matchDate(), $days);

        $candidates = Job::query()
            ->whereRaw('(part_cost_cents - coalesce(tech_parts_cost_cents, 0)) = ?', [$txn->amount_cents])
            ->whereBetween('created_at', [$start, $end])
            ->whereNotIn('id', $this->linkedJobIds(BankMatchKind::PartPurchase))
            ->get();

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /**
     * Outgoing zelle → an End-of-Day payout, matched ONLY when it's the exact
     * amount AND settled the same (Pacific) day as the transaction's initiation
     * date (authorized_date when Chase gives it, else the posted date) — no date
     * window, no off-amount guesses. Keying on the initiation date is what lets a
     * payout stamped Jul 10 match a Zelle that posts Jul 13. When the description
     * names a single technician (techFor), that tech's own same-day exact payout
     * wins; otherwise a same-day exact payout is disambiguated by name.
     */
    private function findTechPayout(BankTransaction $txn): ?TechSettlement
    {
        [$start, $end] = $this->windowAround($txn->matchDate(), 0);
        $linkedIds = BankTransactionMatch::query()->whereNotNull('tech_settlement_id')->pluck('tech_settlement_id');

        $candidates = TechSettlement::query()
            // Warranty return-visit pay settles as its own type but reconciles
            // against a Chase Zelle exactly like an End-of-Day payout.
            ->whereIn('type', ['eod_payout', 'warranty_payout'])
            ->where('amount_cents', $txn->amount_cents)
            ->where('amount_cents', '>', 0)
            // An undone settlement was reversed — never a candidate to auto-link.
            ->whereNull('undone_at')
            ->whereBetween('settled_at', [$start, $end])
            ->whereNotIn('id', $linkedIds)
            ->with('technician')
            ->get();

        // Prefer the technician the description names, if that resolves to
        // exactly one of the same-day exact-amount payouts.
        $tech = $this->techFor($txn);
        if ($tech) {
            $mine = $candidates->where('technician_id', $tech->id);
            if ($mine->count() === 1) {
                return $mine->first();
            }
        }

        return $this->disambiguate($candidates, $txn, fn (TechSettlement $s) => $s->technician?->zelleMatchName());
    }

    // ---- Helpers ----

    /**
     * One candidate → take it, UNLESS $requireNameMatch (see findCustomerPayment()'s
     * doc comment — a candidate that's a partially-claimed payment line always
     * sets this) forces the name check even for a lone candidate. Several →
     * keep only those whose person-name tokens appear in the bank description;
     * take the survivor if exactly one remains.
     */
    private function disambiguate(Collection $candidates, BankTransaction $txn, callable $nameOf, bool $requireNameMatch = false): mixed
    {
        if ($candidates->isEmpty()) {
            return null;
        }
        if ($candidates->count() === 1 && ! $requireNameMatch) {
            return $candidates->first();
        }

        $description = mb_strtolower($txn->name);
        $byName = $candidates->filter(function ($candidate) use ($description, $nameOf) {
            $name = (string) $nameOf($candidate);

            foreach (preg_split('/\s+/', mb_strtolower($name)) ?: [] as $token) {
                if (mb_strlen($token) >= 3 && str_contains($description, $token)) {
                    return true;
                }
            }

            return false;
        });

        return $byName->count() === 1 ? $byName->first() : null;
    }

    /** True when $needle appears as a whole (punctuation-trimmed) token in the description. */
    private function tokenInDescription(string $needle, string $description): bool
    {
        foreach (preg_split('/\s+/', $description) ?: [] as $token) {
            if (trim($token, ".,'-") === $needle) {
                return true;
            }
        }

        return false;
    }

    /** UTC datetime range spanning ±$days around a Y-m-d bank date. */
    private function windowAround(string $date, int $days): array
    {
        $day = Carbon::parse($date, config('glass.timezone'));

        return [
            $day->copy()->subDays($days)->startOfDay()->utc(),
            $day->copy()->addDays($days)->endOfDay()->utc(),
        ];
    }

    private function linkedJobIds(BankMatchKind $kind): Collection
    {
        return BankTransactionMatch::query()
            ->where('kind', $kind->value)
            ->whereNotNull('job_id')
            ->pluck('job_id');
    }

    private function link(
        BankTransaction $txn,
        BankMatchKind $kind,
        ?Job $job = null,
        ?TechSettlement $settlement = null,
        ?string $miscCategory = null,
        bool $confirmed = false,
        ?JobPayment $jobPayment = null,
    ): void {
        // Tech payouts only ever auto-link at the exact amount (findTechPayout),
        // so an auto match is never an amount mismatch.
        $txn->matches()->create([
            'kind' => $kind->value,
            'job_id' => $job?->id,
            'job_payment_id' => $jobPayment?->id,
            'tech_settlement_id' => $settlement?->id,
            'misc_category' => $miscCategory,
            'source' => 'auto',
            'expected_amount_cents' => null,
        ]);

        $txn->update([
            'match_status' => $confirmed ? BankTransaction::STATUS_MATCHED : BankTransaction::STATUS_SUGGESTED,
            'matched_at' => $confirmed ? now() : null,
        ]);
    }
}
