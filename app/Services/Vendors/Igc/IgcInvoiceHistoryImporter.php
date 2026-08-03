<?php

namespace App\Services\Vendors\Igc;

use App\Enums\PartsSupplier;
use App\Enums\VendorDocumentType;
use App\Models\Employee;
use App\Models\VendorDocument;
use App\Models\VendorImport;
use App\Models\VendorPayment;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

/**
 * Ingests IGC's "Invoice Payment/Returns History" XLSX export — one row per
 * order/invoice (Invoice # | Date | Total | Balance | Payments/Credits/Returns),
 * a handful of junk rows before the real header (the real export carries just
 * one title row; scanning for the header text rather than hardcoding a row
 * number keeps this working regardless of exactly how many).
 *
 * ⚠️ R0.2 — the Payments/Credits/Returns cell can hold SEVERAL memos on
 * separate lines (11 of 202 real rows do). Every line is split and parsed
 * independently — reading only the first line under-counts (e.g. invoice
 * 1919968 carries both an $84.80 and a $30.00 payment on two lines).
 *
 * ⚠️ R0.4 — every amount stays SIGNED. Never abs(), never filtered out. A
 * payment can itself be negative (a reversal — invoice 1890268's "-78.24"
 * payment on 04/17 undoes its own "+78.24" payment on 03/16), and losing
 * that sign breaks both the balance invariant below and
 * IgcChargeReconciler's exact-sum charge grouping.
 *
 * ⚠️ §0/C3 — the universal balance invariant is asserted on EVERY row before
 * anything is written for it:
 *
 *     balance == total + sum(signed credit memos) - sum(signed payments)
 *
 * Verified 202/202 on the real export. A row that fails it is a PARSE bug,
 * not a data problem — nothing money-bearing is written for that row, the
 * failure is recorded on the VendorImport (result=failed, error lists every
 * bad invoice), and every OTHER row in the same file still commits normally
 * (per-row transactions) so one bad row can't swallow 200 good ones.
 *
 * A numbered invoice document (document_number) never carries a billing
 * cycle of its own — IGC has no calendar rule (see the vendor_documents
 * migration note) — so cut_date = settle_date = document_date always.
 *
 * Re-uploading an overlapping export window (the recommended pull is 21
 * days, wider than IGC's own irregular order cadence — see IgcUploadNagAlert)
 * is the normal case: documents are upserted on (supplier, document_number),
 * and payment/credit memo lines are upserted by a stable fingerprint
 * (App\Models\VendorPayment) so a re-import never duplicates a row and never
 * disturbs one already linked to a Chase transaction.
 */
class IgcInvoiceHistoryImporter
{
    private const HEADER_INVOICE_LABEL = 'invoice #';

    /**
     * @param  string  $path  Filesystem path to the uploaded XLSX (openspout
     *                        reads from a real file, not an in-memory string —
     *                        unlike the Mygrant CSV/PDF importers, which take
     *                        raw file contents).
     */
    public function import(string $path, ?Employee $actor, string $filename): VendorImport
    {
        try {
            $rows = $this->readRows($path);
        } catch (Throwable $e) {
            return $this->failed($filename, $actor, 'Could not read the XLSX file: '.$e->getMessage());
        }

        if ($rows === null) {
            return $this->failed($filename, $actor, 'Could not find the header row (Invoice # / Date / Total / '
                .'Balance / Payments/Credits/Returns) in this file — is it the right export?');
        }

        $rowsParsed = 0;
        $paymentsCreated = 0;
        $documentsCreated = 0;
        $documentsUpdated = 0;
        $touchedDocumentIds = [];
        $failures = [];

        foreach ($rows as $row) {
            $invoiceNumber = self::cellToString($row[0] ?? null);
            if ($invoiceNumber === '') {
                continue; // a genuinely blank trailing row — nothing to key an upsert on
            }

            $rowsParsed++;

            $date = self::parseSlashDate(self::cellToString($row[1] ?? null));
            $totalCents = self::centsFromCell($row[2] ?? null);
            $balanceCents = self::centsFromCell($row[3] ?? null);
            $memoRaw = self::cellToString($row[4] ?? null);

            $memoLines = self::parseMemoLines($memoRaw);
            $unparsed = array_filter($memoLines, fn (array $l) => $l['kind'] === 'unparsed');

            if ($unparsed !== []) {
                $failures[] = "{$invoiceNumber}: unrecognized memo line(s) — ".
                    implode(' / ', array_column($unparsed, 'raw'));

                continue;
            }

            if ($date === null) {
                $failures[] = "{$invoiceNumber}: could not parse the Date column.";

                continue;
            }

            $creditsSumCents = array_sum(array_map(
                fn (array $l) => $l['amount_cents'],
                array_values(array_filter($memoLines, fn (array $l) => $l['kind'] === 'credit'))
            ));
            $paymentsSumCents = array_sum(array_map(
                fn (array $l) => $l['amount_cents'],
                array_values(array_filter($memoLines, fn (array $l) => $l['kind'] === 'payment'))
            ));

            $expectedBalance = $totalCents + $creditsSumCents - $paymentsSumCents;
            if ($expectedBalance !== $balanceCents) {
                $failures[] = "{$invoiceNumber}: balance invariant failed — total ".Money::format($totalCents)
                    .' + credits '.Money::format($creditsSumCents).' - payments '.Money::format($paymentsSumCents)
                    .' = '.Money::format($expectedBalance).', but the file states balance '.Money::format($balanceCents).'.';

                continue;
            }

            DB::transaction(function () use (
                $invoiceNumber, $date, $totalCents, $balanceCents, $memoLines,
                &$documentsCreated, &$documentsUpdated, &$paymentsCreated, &$touchedDocumentIds
            ) {
                $document = VendorDocument::firstOrNew([
                    'supplier' => PartsSupplier::Igc->value,
                    'document_number' => $invoiceNumber,
                ]);
                $wasNew = ! $document->exists;

                $document->fill([
                    'type' => ($totalCents < 0 ? VendorDocumentType::CreditMemo : VendorDocumentType::Invoice)->value,
                    // No billing cycle of its own — see the migration note.
                    'document_date' => $date,
                    'cut_date' => $date,
                    'settle_date' => $date,
                    'net_amount_cents' => $totalCents,
                    'balance_cents' => $balanceCents,
                    'source' => $document->source ?: 'invoice_history',
                ]);
                $document->save();

                $wasNew ? $documentsCreated++ : $documentsUpdated++;
                $touchedDocumentIds[] = $document->id;

                $ordinals = [];
                foreach ($memoLines as $entry) {
                    $sig = $entry['kind'].'|'.$entry['date'].'|'.$entry['amount_cents'].'|'.($entry['auth_code'] ?? '');
                    $ordinal = $ordinals[$sig] ?? 0;
                    $ordinals[$sig] = $ordinal + 1;

                    $fingerprint = sha1(implode('|', [
                        PartsSupplier::Igc->value, $invoiceNumber, $entry['kind'],
                        $entry['date'], $entry['amount_cents'], $entry['auth_code'] ?? '', $ordinal,
                    ]));

                    $payment = VendorPayment::firstOrNew(['fingerprint' => $fingerprint]);
                    if ($payment->exists) {
                        // Already on file — leave it alone so a bank_transaction_id
                        // link the reconciler already made survives a re-import.
                        continue;
                    }

                    $payment->fill([
                        'supplier' => PartsSupplier::Igc->value,
                        'vendor_document_id' => $document->id,
                        'kind' => $entry['kind'],
                        'payment_date' => $entry['date'],
                        'amount_cents' => $entry['amount_cents'],
                        'auth_code' => $entry['auth_code'],
                        'memo_raw' => $entry['raw'],
                    ]);
                    $payment->save();
                    $paymentsCreated++;
                }
            });
        }

        return VendorImport::create([
            'supplier' => PartsSupplier::Igc->value,
            'kind' => 'igc_invoice_history',
            'filename' => $filename,
            'uploaded_by' => $actor?->id,
            'lines_parsed' => $rowsParsed,
            'lines_created' => $paymentsCreated,
            'lines_updated' => $documentsUpdated,
            'documents_touched' => count(array_unique($touchedDocumentIds)),
            'result' => $failures === [] ? 'ok' : 'failed',
            'error' => $failures === [] ? null : implode(' | ', $failures),
        ]);
    }

    /**
     * Locate the header row (by its own text, not a hardcoded row number —
     * different pulls of this report have carried different amounts of
     * leading junk) and return every data row beneath it as a plain
     * 0-indexed array (Invoice # / Date / Total / Balance / memo).
     *
     * @return array<int, array<int, mixed>>|null null when no header row is found.
     */
    private function readRows(string $path): ?array
    {
        $reader = new XlsxReader;
        $reader->open($path);

        $rows = [];
        $headerFound = false;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $values = $row->toArray();

                if (! $headerFound) {
                    if (mb_strtolower(trim((string) ($values[0] ?? ''))) === self::HEADER_INVOICE_LABEL) {
                        $headerFound = true;
                    }

                    continue;
                }

                $rows[] = $values;
            }

            break; // only the first sheet
        }

        $reader->close();

        return $headerFound ? $rows : null;
    }

    /**
     * Split a (possibly multi-line) memo cell and parse each line
     * independently — R0.2. Regexes exactly as validated against the real
     * export (the trailing `by\s*` field on a Payment line is often empty).
     *
     * @return array<int, array{kind: string, amount_cents?: int, date?: ?string, auth_code?: ?string, raw: string}>
     */
    public static function parseMemoLines(string $memo): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $memo) ?: [];
        $parsed = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^Payment of (-?[\d,.]+) on (\d{1,2}\/\d{1,2}\/\d{2}) by\s*(.*)$/i', $line, $m)) {
                $auth = null;
                if (preg_match('/AUTH#:\s*(\S+)/i', $m[3], $am)) {
                    $auth = $am[1];
                }

                $parsed[] = [
                    'kind' => 'payment',
                    'amount_cents' => Money::centsFromDecimalString($m[1]),
                    'date' => self::parseSlashDate($m[2]),
                    'auth_code' => $auth,
                    'raw' => $line,
                ];

                continue;
            }

            if (preg_match('/^Credit\/Return of (-?[\d,.]+) on (\d{1,2}\/\d{1,2}\/\d{2})/i', $line, $m)) {
                $parsed[] = [
                    'kind' => 'credit',
                    'amount_cents' => Money::centsFromDecimalString($m[1]),
                    'date' => self::parseSlashDate($m[2]),
                    'auth_code' => null,
                    'raw' => $line,
                ];

                continue;
            }

            $parsed[] = ['kind' => 'unparsed', 'raw' => $line];
        }

        return $parsed;
    }

    /** "07/24/26" -> "2026-07-24". Two-digit years are YY -> 20YY (never Carbon's 1970-pivot guess). */
    public static function parseSlashDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2})$#', $raw, $m)) {
            return sprintf('%04d-%02d-%02d', 2000 + (int) $m[3], (int) $m[1], (int) $m[2]);
        }

        // Defensive fallback for a differently-formatted date (e.g. already 4-digit year).
        try {
            return Carbon::parse($raw)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private static function centsFromCell(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_string($value)) {
            return Money::centsFromDecimalString($value);
        }

        // openspout returns a genuinely numeric Excel cell as int|float —
        // format to a fixed 2-decimal string first so centsFromDecimalString
        // (an exact string split) does the final conversion, never a
        // float-cents multiply that could round wrong.
        return Money::centsFromDecimalString(number_format((float) $value, 2, '.', ''));
    }

    private static function cellToString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            // A numeric cell that's really an integer id (e.g. an invoice
            // number Excel stored as a number) — avoid "1924343.0".
            return $value === floor($value) ? (string) (int) $value : (string) $value;
        }

        return trim((string) $value);
    }

    private function failed(string $filename, ?Employee $actor, string $error): VendorImport
    {
        return VendorImport::create([
            'supplier' => PartsSupplier::Igc->value,
            'kind' => 'igc_invoice_history',
            'filename' => $filename,
            'uploaded_by' => $actor?->id,
            'result' => 'failed',
            'error' => $error,
        ]);
    }
}
