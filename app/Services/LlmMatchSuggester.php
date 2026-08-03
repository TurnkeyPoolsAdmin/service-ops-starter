<?php

namespace App\Services;

use App\Enums\BankMatchKind;
use App\Models\BankTransaction;
use App\Models\BankTransactionMatch;
use App\Models\Job;
use App\Models\Setting;
use App\Models\TechSettlement;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Optional LLM assist for the leftover Needs-review pile. The deterministic
 * BankMatcher stays the source of truth — this is a strictly ON-DEMAND, opt-in
 * helper for the transactions it couldn't resolve. Given one unmatched
 * transaction and a bounded list of REAL candidates (recent jobs, same-day
 * payouts, owners, misc categories), it asks Claude to pick the single most
 * likely classification, then maps the answer back to a real record and writes
 * it as a *suggested* match (source = 'ai') for a human to confirm — exactly the
 * same one-click confirm/unmatch flow as the deterministic suggestions. It never
 * auto-confirms and never invents an id (the model only ever picks from the
 * candidate lists we hand it).
 *
 * Carries a per-call Anthropic API cost, so it is gated behind an explicit toggle
 * + API key in Admin → Integrations and is only ever invoked by the "Ask AI"
 * button — there is no cron sweeping the pile.
 */
class LlmMatchSuggester
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const MODEL = 'claude-haiku-4-5-20251001';

    public function enabled(): bool
    {
        return Setting::get('llm_matching_enabled', '0') === '1' && filled(Setting::get('anthropic_api_key'));
    }

    /**
     * Ask the model to classify one unmatched transaction. Returns the created
     * BankTransactionMatch (status flipped to suggested), or null when the model
     * declined / the call failed / nothing mapped. Never throws to the caller —
     * failures are logged and surface as "no suggestion".
     */
    public function suggestFor(BankTransaction $txn): ?BankTransactionMatch
    {
        if (! $this->enabled() || $txn->match_status === BankTransaction::STATUS_MATCHED) {
            return null;
        }

        $jobs = $this->candidateJobs($txn);
        $payouts = $this->candidatePayouts($txn);

        $decision = $this->ask($txn, $jobs, $payouts);
        if ($decision === null) {
            return null;
        }

        return $this->applyDecision($txn, $decision, $jobs, $payouts);
    }

    // ---- Candidate gathering (only REAL records the model may choose from) ----

    /** Recent jobs that could plausibly relate to this transaction, capped. */
    private function candidateJobs(BankTransaction $txn): \Illuminate\Support\Collection
    {
        $days = (int) config('glass.bank_matching.name_window_days', 14);
        [$start, $end] = $this->windowAround($txn->matchDate(), max($days, 30));

        return Job::query()
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('payment_received_at', [$start, $end])
                    ->orWhereBetween('created_at', [$start, $end]);
            })
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'job_number', 'customer_name_snapshot', 'amount_collected_cents', 'part_cost_cents', 'payment_method', 'created_at', 'payment_received_at']);
    }

    /** Tech End-of-Day payouts near this transaction's date, capped. */
    private function candidatePayouts(BankTransaction $txn): \Illuminate\Support\Collection
    {
        if ($txn->amount_cents <= 0) {
            return collect();
        }

        [$start, $end] = $this->windowAround($txn->matchDate(), 3);
        $linked = BankTransactionMatch::query()->whereNotNull('tech_settlement_id')->pluck('tech_settlement_id');

        return TechSettlement::query()
            ->where('type', 'eod_payout')
            ->where('amount_cents', '>', 0)
            ->whereBetween('settled_at', [$start, $end])
            ->whereNotIn('id', $linked)
            ->with('technician')
            ->limit(15)
            ->get();
    }

    // ---- The model call ----

    private function ask(BankTransaction $txn, $jobs, $payouts): ?array
    {
        $direction = $txn->amount_cents < 0 ? 'money IN (a deposit / incoming payment)' : 'money OUT (a charge / outgoing payment)';
        $amount = '$'.number_format(abs($txn->amount_cents) / 100, 2);

        $jobLines = $jobs->map(fn (Job $j, $i) => "  [$i] job {$j->job_number} · ".($j->customer_name_snapshot ?: 'no name')
            .' · collected '.$this->dollars($j->amount_collected_cents).' ('.($j->payment_method?->value ?? 'unpaid').')'
            .' · part '.$this->dollars($j->part_cost_cents)
            .' · created '.Carbon::parse($j->created_at)->format('Y-m-d'))->values()->implode("\n");

        $payoutLines = $payouts->map(fn (TechSettlement $s, $i) => "  [$i] payout to {$s->technician?->name} · "
            .($s->for_date ?? Carbon::parse($s->settled_at)->format('Y-m-d')).' · '.$this->dollars($s->amount_cents))->values()->implode("\n");

        $owners = collect(BankMatchKind::dividendRecipients())->map(fn ($name, $key) => "\"$key\" ($name)")->implode(', ');
        $miscKeys = collect(BankMatchKind::miscCategories())->map(fn ($label, $key) => "\"$key\" ($label)")->implode(', ');

        $prompt = <<<TXT
        A bank transaction on our auto-glass business's Chase account needs to be classified for bookkeeping.

        TRANSACTION
          Description: {$txn->name}
          Merchant: {$txn->merchant_name}
          Amount: {$amount} — {$direction}
          Date: {$txn->matchDate()}

        Pick the SINGLE most likely classification. You may ONLY reference the indices/keys listed below — never invent one.

        CANDIDATE JOBS (for a customer payment or a parts purchase):
        {$jobLines}

        CANDIDATE TECH PAYOUTS (for an outgoing Zelle to a technician):
        {$payoutLines}

        OWNERS (an outgoing Zelle to one of these is a "dividend"): {$owners}
        MISC CATEGORIES: {$miscKeys}

        Reply with ONLY a JSON object, no prose, shaped exactly like:
        {"kind":"job_payment|part_purchase|tech_payout|dividend|misc|none","job_index":<int|null>,"payout_index":<int|null>,"owner":"<key|null>","misc":"<key|null>","confidence":<0..1>,"reason":"<short>"}
        Use "none" when nothing is a confident fit. A customer payment (job_payment) is money IN; a parts purchase, tech payout and dividend are money OUT.
        TXT;

        try {
            $response = Http::withHeaders([
                'x-api-key' => (string) Setting::get('anthropic_api_key'),
                'anthropic-version' => '2023-06-01',
            ])->timeout(30)->post(self::ENDPOINT, [
                'model' => self::MODEL,
                'max_tokens' => 300,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);
        } catch (\Throwable $e) {
            Log::error('LLM match suggester call failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::error('LLM match suggester API error', ['status' => $response->status(), 'body' => $response->json('error.message')]);

            return null;
        }

        $text = $response->json('content.0.text');

        return $this->parseJson(is_string($text) ? $text : '');
    }

    /** Pull the first JSON object out of the model's reply, defensively. */
    private function parseJson(string $text): ?array
    {
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $decoded = json_decode($m[0], true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    // ---- Turn the decision into a real suggested match ----

    private function applyDecision(BankTransaction $txn, array $decision, $jobs, $payouts): ?BankTransactionMatch
    {
        $kind = $decision['kind'] ?? 'none';
        $reason = 'AI: '.trim((string) ($decision['reason'] ?? ''));

        $attributes = match ($kind) {
            'job_payment', 'part_purchase' => $this->jobAttributes($kind, $jobs, $decision),
            'tech_payout' => $this->payoutAttributes($payouts, $decision),
            'dividend' => $this->dividendAttributes($decision),
            'misc' => $this->miscAttributes($decision),
            default => null,
        };

        if ($attributes === null) {
            return null;
        }

        // Replace any prior (unconfirmed) suggestion; a matched row is left alone
        // by the guard in suggestFor().
        $txn->matches()->delete();

        $match = $txn->matches()->create(array_merge($attributes, [
            'source' => 'ai',
            'note' => $reason,
            'expected_amount_cents' => null,
        ]));

        $txn->update([
            'match_status' => BankTransaction::STATUS_SUGGESTED,
            'matched_at' => null,
        ]);

        return $match;
    }

    private function jobAttributes(string $kind, $jobs, array $decision): ?array
    {
        $job = $jobs->values()->get((int) ($decision['job_index'] ?? -1));
        if (! $job) {
            return null;
        }

        return ['kind' => $kind, 'job_id' => $job->id];
    }

    private function payoutAttributes($payouts, array $decision): ?array
    {
        $settlement = $payouts->values()->get((int) ($decision['payout_index'] ?? -1));
        if (! $settlement) {
            return null;
        }

        return ['kind' => BankMatchKind::TechPayout->value, 'tech_settlement_id' => $settlement->id];
    }

    private function dividendAttributes(array $decision): ?array
    {
        $owner = $decision['owner'] ?? null;
        if (! $owner || ! array_key_exists($owner, BankMatchKind::dividendRecipients())) {
            return null;
        }

        return ['kind' => BankMatchKind::Dividend->value, 'misc_category' => $owner];
    }

    private function miscAttributes(array $decision): ?array
    {
        $key = $decision['misc'] ?? null;
        if (! $key || ! array_key_exists($key, BankMatchKind::miscCategories())) {
            return null;
        }

        return ['kind' => BankMatchKind::Misc->value, 'misc_category' => $key];
    }

    // ---- Helpers ----

    private function dollars(?int $cents): string
    {
        return $cents === null ? '—' : '$'.number_format($cents / 100, 2);
    }

    private function windowAround(string $date, int $days): array
    {
        $day = Carbon::parse($date, config('glass.timezone'));

        return [
            $day->copy()->subDays($days)->startOfDay()->utc(),
            $day->copy()->addDays($days)->endOfDay()->utc(),
        ];
    }
}
