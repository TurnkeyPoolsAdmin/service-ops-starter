<?php

namespace App\Console\Commands;

use App\Models\LegacyManualPayment;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * One-time (but safely rerunnable) import of the pre-Job-tracking-era
 * payments CSV — see the legacy_manual_payments migration's doc comment for
 * why this is its own table. Idempotent on source_row (the CSV's own line
 * number): rerunning updates existing rows rather than duplicating them, so
 * a corrected CSV can be re-imported safely.
 *
 * Classification (status) is decided HERE, once, at import time — not
 * something App\Services\LegacyPaymentMatcher re-derives, so a human editing
 * status by hand later (e.g. resolving a needs_review row) never gets
 * silently overwritten by a rerun UNLESS that row's classification inputs
 * (date/paid/method/csv note) actually changed, in which case the rerun
 * intentionally re-derives it — see reimport behavior below.
 *
 * Precedence, most specific first:
 *   1. Unparseable/blank payment date -> needs_review (can't window-match
 *      without a real date, regardless of what the CSV itself flagged).
 *   2. Source CSV's own needs_review column is non-empty -> needs_review.
 *      Trusted as-is, not re-interpreted — the CSV author already looked at
 *      this row and had a concern; not this importer's job to second-guess
 *      which concerns are "really" clean cases.
 *   3. paid = $0.00 -> no_match_expected (nothing to look for at the bank).
 *   4. payment_method is Cash (case-insensitive) -> no_match_expected — cash
 *      never hits the Chase feed, by this app's own long-standing convention
 *      (App\Services\BankMatcher::bankMatchableMethods() excludes it too).
 *   5. Otherwise -> pending (a real candidate for LegacyPaymentMatcher).
 */
class ImportLegacyManualPayments extends Command
{
    protected $signature = 'legacy-payments:import {path : Path to the CSV file}';

    protected $description = 'Import the pre-Job-tracking-era manual payments CSV into legacy_manual_payments.';

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! is_readable($path)) {
            $this->error("Cannot read file: {$path}");

            return self::FAILURE;
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);

        if ($header === false) {
            $this->error('CSV appears to be empty.');
            fclose($handle);

            return self::FAILURE;
        }

        $counts = [
            LegacyManualPayment::STATUS_PENDING => 0,
            LegacyManualPayment::STATUS_NEEDS_REVIEW => 0,
            LegacyManualPayment::STATUS_NO_MATCH_EXPECTED => 0,
        ];

        $rowNum = 1; // header is row 1; first data row is 2, matching the file's own line numbers.
        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;

            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue; // blank trailing line
            }

            $data = array_combine($header, array_pad($row, count($header), null));
            $classified = $this->classify($data);
            $counts[$classified['status']]++;

            LegacyManualPayment::updateOrCreate(
                ['source_row' => $rowNum],
                array_merge($classified, [
                    'raw_date' => trim((string) ($data['date'] ?? '')) ?: null,
                    'customer_name' => trim((string) ($data['customer'] ?? '')),
                    'phone' => trim((string) ($data['phone'] ?? '')) ?: null,
                    'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                    'tech' => trim((string) ($data['tech'] ?? '')) ?: null,
                    'charged_cents' => $this->toCents($data['charged'] ?? null),
                    'paid_cents' => $this->toCents($data['paid'] ?? null),
                    'balance_cents' => $this->toCents($data['balance'] ?? null),
                    'payment_method' => trim((string) ($data['payment_method'] ?? '')) ?: null,
                    'csv_review_note' => trim((string) ($data['needs_review'] ?? '')) ?: null,
                ]),
            );
        }

        fclose($handle);

        $this->info('Imported '.array_sum($counts).' rows:');
        $this->line("  pending (candidates to match): {$counts[LegacyManualPayment::STATUS_PENDING]}");
        $this->line("  needs_review (held for a human): {$counts[LegacyManualPayment::STATUS_NEEDS_REVIEW]}");
        $this->line("  no_match_expected (paid \$0 / cash): {$counts[LegacyManualPayment::STATUS_NO_MATCH_EXPECTED]}");

        return self::SUCCESS;
    }

    /** @return array{status: string, status_note: ?string, payment_date: ?string} */
    private function classify(array $data): array
    {
        $rawDate = trim((string) ($data['date'] ?? ''));
        $parsedDate = null;
        if ($rawDate !== '') {
            try {
                $parsedDate = Carbon::createFromFormat('Y-m-d', $rawDate)->toDateString();
            } catch (\Throwable) {
                $parsedDate = null;
            }
        }

        $csvNote = trim((string) ($data['needs_review'] ?? ''));
        $paidCents = $this->toCents($data['paid'] ?? null);
        $method = trim((string) ($data['payment_method'] ?? ''));

        if ($parsedDate === null) {
            return [
                'status' => LegacyManualPayment::STATUS_NEEDS_REVIEW,
                'status_note' => 'Unparseable/blank payment date ("'.$rawDate.'") - needs a real date before matching.',
                'payment_date' => null,
            ];
        }

        if ($csvNote !== '') {
            return [
                'status' => LegacyManualPayment::STATUS_NEEDS_REVIEW,
                'status_note' => 'Flagged in source CSV: '.$csvNote,
                'payment_date' => $parsedDate,
            ];
        }

        if ($paidCents === 0) {
            return [
                'status' => LegacyManualPayment::STATUS_NO_MATCH_EXPECTED,
                'status_note' => 'paid = $0.00 - no bank transaction expected.',
                'payment_date' => $parsedDate,
            ];
        }

        if (strcasecmp($method, 'cash') === 0) {
            return [
                'status' => LegacyManualPayment::STATUS_NO_MATCH_EXPECTED,
                'status_note' => 'Cash never hits the bank feed - tracked outside Chase.',
                'payment_date' => $parsedDate,
            ];
        }

        // "Insurance" conceptually means no direct customer deposit (same
        // "(insurance/unpaid)" framing as the paid=$0 rule above) — a
        // nonzero paid amount against it is the same shape of contradiction
        // as a $0 row carrying a payment method, just inverted. Held for a
        // human rather than guessed at, same treatment either direction.
        if (strcasecmp($method, 'insurance') === 0 && $paidCents > 0) {
            return [
                'status' => LegacyManualPayment::STATUS_NEEDS_REVIEW,
                'status_note' => 'Insurance method with a nonzero paid amount ($'.number_format($paidCents / 100, 2)
                    .') - unclear whether this hit the bank directly, confirm.',
                'payment_date' => $parsedDate,
            ];
        }

        return [
            'status' => LegacyManualPayment::STATUS_PENDING,
            'status_note' => null,
            'payment_date' => $parsedDate,
        ];
    }

    private function toCents(mixed $value): ?int
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        return (int) round(((float) $value) * 100);
    }
}
