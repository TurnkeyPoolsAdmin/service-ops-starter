<?php

namespace App\Services\Stripe;

use App\Models\BankTransaction;
use App\Models\StripePayout;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Links a resolved (or partial) StripePayout to the actual Chase deposit it
 * shows up as — App\Models\BankTransaction — by exact amount + arrival date,
 * falling back to a small day window. Runs alongside bank:suggest-matches
 * (see routes/console.php), right after it.
 *
 * Deliberately narrow: this ONLY stamps stripe_payouts.bank_transaction_id.
 * It does NOT create BankTransactionMatch rows or touch
 * bank_transactions.match_status — that's the Match-modal UI wiring
 * (deferred until this whole pipeline has been sanity-checked against a real
 * payout). A candidate transaction already claimed by the OLD heuristic
 * matcher (BankMatcher — match_status suggested/matched) is left alone
 * rather than fought over; only genuinely UNMATCHED deposits are candidates
 * here, so the two matchers never collide.
 */
class StripePayoutReconciler
{
    /** Sweep every unmatched resolved/partial payout; returns state => count. */
    public function run(): array
    {
        $tally = ['matched' => 0, 'ambiguous' => 0, 'no_candidate' => 0];

        foreach ($this->candidatePayouts() as $payout) {
            $state = $this->matchOne($payout);
            $tally[$state] = ($tally[$state] ?? 0) + 1;
        }

        return $tally;
    }

    /** Resolved or partial payouts not yet linked to a Chase deposit. */
    public function candidatePayouts(): Collection
    {
        return StripePayout::query()
            ->whereIn('resolution_status', [StripePayout::STATUS_RESOLVED, StripePayout::STATUS_PARTIAL])
            ->unmatched()
            ->get();
    }

    private function matchOne(StripePayout $payout): string
    {
        $candidates = $this->depositCandidates((int) $payout->amount_cents, $payout->arrival_date);

        if ($candidates->count() === 1) {
            $payout->update(['bank_transaction_id' => $candidates->first()->id]);

            return 'matched';
        }

        return $candidates->isEmpty() ? 'no_candidate' : 'ambiguous';
    }

    /**
     * Posted, genuinely unmatched, Stripe-looking deposits for this exact
     * amount (bank_transactions.amount_cents is Plaid-signed — a deposit is
     * the NEGATIVE of the payout's positive amount), preferring the same
     * matchDate() as arrival_date and falling back to +/-2 days.
     *
     * ⚠️ The +/-2 day window is a deliberately wide, UNVALIDATED superset —
     * kept wide on purpose rather than tightened on thin evidence. The only
     * real data pulled so far (2026-07-28, tracing confirmed
     * BankTransactionMatch rows -> JobPayment.collected_at against the
     * actual deposit date, before this reconciler ever ran against
     * production) was 3 matched Stripe deposits total: one clean AUTO match
     * showing collected_at one calendar day before the deposit posted
     * (-1 day), one MANUAL match consistent with that same -1 day pattern
     * but not independent confirmation (human-entered, could just be
     * agreeing with the auto one's neighborhood), and one MANUAL match
     * whose 3 lines all had collected_at AFTER the deposit date — physically
     * impossible for real Stripe timing, so a loose human match made on
     * amount alone, discarded as noise. Net: effectively n=1 independent
     * observation pointing at "next calendar day," not the "1-2 days"
     * originally assumed and not same-day either. Too thin to narrow the
     * window on — revisit once stripe:match-payouts has actually run
     * against production and produced a real sample size.
     */
    private function depositCandidates(int $payoutAmountCents, string $arrivalDate): Collection
    {
        $sameAmount = BankTransaction::query()
            ->where('pending', false)
            ->where('match_status', BankTransaction::STATUS_UNMATCHED)
            ->where('amount_cents', -$payoutAmountCents)
            ->where(fn (Builder $q) => $q->where('name', 'like', '%stripe%')->orWhere('merchant_name', 'like', '%stripe%'))
            ->get();

        $sameDay = $sameAmount->filter(fn (BankTransaction $t) => $t->matchDate() === $arrivalDate);
        if ($sameDay->isNotEmpty()) {
            return $sameDay;
        }

        $arrival = Carbon::parse($arrivalDate);

        return $sameAmount->filter(
            fn (BankTransaction $t) => abs($arrival->diffInDays(Carbon::parse($t->matchDate()), false)) <= 2
        );
    }
}
