<?php

namespace App\Services\Vendors\Mygrant;

use App\Support\Money;
use App\Support\PartNumber;
use Carbon\Carbon;
use RuntimeException;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Parses a Mygrant credit-memo PDF's TEXT LAYER (via smalot/pdfparser) into
 * a ParsedCreditMemo DTO. NO database writes happen here — see
 * CreditMemoImporter for the transactional import, and for the sum
 * self-check that is the whole reason trusting a scraped PDF is safe.
 *
 * ⚠️ The extracted text order is SCRAMBLED — verified against a real memo
 * (smalot/pdfparser's getText()): the credit-line rows extract as their own
 * contiguous block, and the "Originally purchased on DR#" reference lines
 * extract as ANOTHER contiguous block elsewhere, in a different order, with
 * no reliable adjacency between a line and its own reference:
 *
 *   Originally purchased on DR# S74263397-1
 *   Originally purchased on DR# S74436602-3
 *   Z10017019-1  07/11/2026  1  DQ10470 YPN ATI  678.97  -54.32
 *   Z10012775-2  07/09/2026  1  FW05851 GTY FYG  3454.57  -276.37
 *   Z10012775-1  07/09/2026  1  FW05851 GTY FYG  3454.57  -276.37
 *   Originally purchased on DR# S74430566-1
 *
 * So this parser extracts the two blocks INDEPENDENTLY with their own
 * regexes and leaves pairing them up to CreditMemoImporter (by part number +
 * amount, never by text position — see its doc comment).
 *
 * Fields are tab-separated in the real PDF's text layer (not spaces) — every
 * regex below uses \s+ so it works either way.
 *
 * ⚠️ A SECOND real credit-memo layout exists (2026-07-30, real memo
 * I05076477-0) — same "credit memo" document type, but structurally
 * different in three ways verified against that real file:
 *  1. Credit-line req numbers are prefixed `S` (Mygrant's ORIGINAL-SALE req#
 *     format), not `Z` (a self-assigned credit-doc number) — this variant
 *     never mints its own credit-line number at all.
 *  2. Each credit-line row carries an extra "FACTOR" column between LIST and
 *     NET (`S74641201-2  07/21/2026  1  DW02852 GTY FYG  5312.00  0.080
 *     424.96` — 7 fields, not 6). Verified real: summing the trailing NET
 *     column across all 23 lines on both pages reconciles to the exact
 *     penny against the PDF's own stated Net Amount ($3,916.71) — this is a
 *     coherent, real format, not corrupted data.
 *  3. It never prints "Originally purchased on DR#" at all — no separate
 *     reference block exists. Since the req# here already IS the
 *     original-sale req# (unlike the `Z`-numbered variant), it doubles as
 *     its own DR# reference — see extractDrReferences()'s fallback.
 *  4. Amounts print POSITIVE (`424.96`), not negative (`-54.32`) — this
 *     parser NORMALIZES every amount to negative (extractNetAmountCents(),
 *     extractCreditLines()) regardless of how the PDF prints it, since a
 *     credit memo's net_amount_cents must always reduce a reconciliation
 *     period's total (VendorChargeReconciler sums net_amount_cents straight
 *     across invoices AND credit memos — see MygrantRealChargesTest).
 * Both variants share the same document-number scheme, Net Amount block
 * format, and column header text ("...LIST FACTOR NET" appears in BOTH
 * PDFs' headers — the FACTOR column isn't new, this parser just never saw a
 * memo whose rows actually populated it before). Expect either shape on any
 * future upload.
 */
class CreditMemoParser
{
    public static function parse(string $pdfContents): ParsedCreditMemo
    {
        $text = self::extractText($pdfContents);
        $lines = self::extractCreditLines($text);

        return new ParsedCreditMemo(
            documentNumber: self::extractDocumentNumber($text),
            documentDate: self::extractDocumentDate($text),
            netAmountCents: self::extractNetAmountCents($text),
            lines: $lines,
            drReferences: self::extractDrReferences($text, $lines),
        );
    }

    private static function extractText(string $pdfContents): string
    {
        $pdf = (new PdfParser)->parseContent($pdfContents);

        return $pdf->getText();
    }

    private static function extractDocumentNumber(string $text): string
    {
        if (! preg_match('/\bI\d{6,}-\d+\b/', $text, $m)) {
            throw new RuntimeException('Mygrant credit memo: could not find a document number (I#######-#) in the PDF text.');
        }

        return $m[0];
    }

    /**
     * The header date ("07/12/2026") is the only date that appears ALONE on
     * its own text line — every other date in the document is embedded
     * inside a tab-separated credit-line row. That makes it immune to the
     * scrambled block ordering described in the class doc comment.
     */
    private static function extractDocumentDate(string $text): string
    {
        if (! preg_match('/^(\d{1,2}\/\d{1,2}\/\d{4})\s*$/m', $text, $m)) {
            throw new RuntimeException('Mygrant credit memo: could not find a standalone document date in the PDF text.');
        }

        return Carbon::createFromFormat('n/j/Y', $m[1])->toDateString();
    }

    /**
     * The real PDF text extracts "Subtotal:", "Sales Tax:", "Net Amount:" as
     * three consecutive label lines, immediately followed by their three
     * values IN THE SAME ORDER (verified: "-607.06", "0", "-607.06") — a
     * side effect of column-based text extraction grouping same-column runs
     * together. That makes the THIRD number after the label block the real
     * Net Amount, which is what we key off (not "whichever number comes
     * right after the words Net Amount", which would be the Subtotal value
     * whenever tax is 0 — coincidentally correct here, not reliably so).
     *
     * Normalized to always be negative (via -abs()) regardless of the sign
     * the PDF itself prints — the FACTOR-column variant (class doc comment)
     * prints every amount positive, but VendorChargeReconciler sums
     * net_amount_cents straight across invoices (positive) and credit memos
     * (must stay negative to actually reduce a period's total).
     */
    private static function extractNetAmountCents(string $text): int
    {
        if (preg_match(
            '/Subtotal:\s*Sales Tax:\s*Net Amount:\s*(-?[\d,]+\.\d{2})\s*(-?[\d,.]+)\s*(-?[\d,]+\.\d{2})/s',
            $text,
            $m
        )) {
            return -abs(Money::centsFromDecimalString($m[3]));
        }

        // Fallback for a non-scrambled / differently-laid-out extraction
        // (a different PDF tool or a future Mygrant template change).
        if (preg_match('/Net Amount:\s*(-?[\d,]+\.\d{2})/', $text, $m)) {
            return -abs(Money::centsFromDecimalString($m[1]));
        }

        throw new RuntimeException('Mygrant credit memo: could not find "Net Amount" in the PDF text.');
    }

    /**
     * One row per credit line, either layout (class doc comment):
     *   Z10012775-1  07/09/2026  1  FW05851 GTY FYG  3454.57          -276.37
     *   S74641201-2  07/21/2026  1  DW02852 GTY FYG  5312.00  0.080    424.96
     * = req no / date / qty / part description / [amount fields] — the LAST
     * number is always the real NET amount, whether it's the only one after
     * the description (6-field layout) or the third of a LIST/FACTOR/NET
     * trio (7-field layout). Captured as one trailing "numbers" group and
     * split in PHP rather than trying to regex-distinguish the two layouts,
     * since a rigid field count would silently drop one layout the moment
     * the other showed up. Amounts normalized to negative — see
     * extractNetAmountCents()'s doc comment for why.
     *
     * @return array<int, array{req_no: string, date: string, qty: int, part_number: string, part_number_base: ?string, amount_cents: int}>
     */
    private static function extractCreditLines(string $text): array
    {
        preg_match_all(
            '/^(\S+)\s+(\d{1,2}\/\d{1,2}\/\d{4})\s+(\d+)\s+(.+?)\s+((?:-?[\d,]+\.\d{2,3}\s+){1,2}-?[\d,]+\.\d{2})\s*$/m',
            $text,
            $matches,
            PREG_SET_ORDER
        );

        return array_map(function (array $m) {
            $amounts = preg_split('/\s+/', trim($m[5])) ?: [];
            $net = (string) end($amounts);

            return [
                'req_no' => $m[1],
                'date' => Carbon::createFromFormat('n/j/Y', $m[2])->toDateString(),
                'qty' => (int) $m[3],
                'part_number' => trim($m[4]),
                'part_number_base' => PartNumber::normalizeBase($m[4]),
                'amount_cents' => -abs(Money::centsFromDecimalString($net)),
            ];
        }, $matches);
    }

    /**
     * @param  array<int, array{req_no: string, date: string, qty: int, part_number: string, part_number_base: ?string, amount_cents: int}>  $lines
     * @return array<int, string>
     */
    private static function extractDrReferences(string $text, array $lines): array
    {
        preg_match_all('/Originally purchased on DR#\s*(\S+)/', $text, $m);
        $refs = array_values(array_unique($m[1]));

        if ($refs !== []) {
            return $refs;
        }

        // The FACTOR-column variant (class doc comment) never prints
        // "Originally purchased on DR#" at all — its credit-line req_no IS
        // the original-sale req# directly (Mygrant's `S...` format, the
        // same format the OTHER variant's explicit DR# references use), not
        // a self-assigned credit-doc number. Fall back to the lines' own
        // req_no only when the text genuinely has no explicit references —
        // never override a real one.
        return array_values(array_unique(array_column($lines, 'req_no')));
    }
}
