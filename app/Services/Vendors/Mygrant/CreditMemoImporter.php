<?php

namespace App\Services\Vendors\Mygrant;

use App\Enums\PartsSupplier;
use App\Enums\VendorDocumentType;
use App\Enums\VendorLineStatus;
use App\Models\Employee;
use App\Models\VendorDocument;
use App\Models\VendorDocumentLine;
use App\Models\VendorImport;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turns a ParsedCreditMemo into database rows. Parsing (CreditMemoParser) and
 * writing (here) are deliberately split so the one safety rule that makes
 * trusting a scraped PDF acceptable — the self-check below — is the FIRST
 * thing this class does, before anything is written.
 */
class CreditMemoImporter
{
    public function import(string $pdfContents, ?Employee $actor, string $filename): VendorImport
    {
        try {
            $parsed = CreditMemoParser::parse($pdfContents);
        } catch (Throwable $e) {
            return $this->failed($filename, $actor, $e->getMessage());
        }

        // The one rule that makes this whole feature safe: refuse to commit
        // anything unless the parsed credit lines sum to EXACTLY what the
        // PDF itself states as the Net Amount. A parsing bug (a missed line,
        // a mis-split amount) shows up as a mismatch here instead of quietly
        // corrupting the ledger.
        $linesSum = $parsed->linesTotalCents();
        if ($linesSum !== $parsed->netAmountCents) {
            return $this->failed($filename, $actor, sprintf(
                'Parsed %d credit line(s) summing to %s, but the PDF states Net Amount %s for %s — refusing to import.',
                count($parsed->lines),
                Money::format($linesSum),
                Money::format($parsed->netAmountCents),
                $parsed->documentNumber,
            ));
        }

        return DB::transaction(fn () => $this->commit($parsed, $actor, $filename));
    }

    private function commit(ParsedCreditMemo $parsed, ?Employee $actor, string $filename): VendorImport
    {
        // Resolve each "Originally purchased on DR#" reference to the
        // vendor_document_lines row it originally billed on (order_line is
        // the one stable join key — see VendorDocumentLine's class doc).
        $resolvedByRef = [];
        $unresolvedDrRefs = [];
        foreach ($parsed->drReferences as $ref) {
            $line = VendorDocumentLine::where('order_line', $ref)->first();
            if ($line) {
                $resolvedByRef[$ref] = $line;
            } else {
                $unresolvedDrRefs[] = $ref;
            }
        }

        // Pair credit lines to resolved DR# lines by (part_number_base,
        // amount) — NEVER by text position, since the PDF's extracted order
        // is scrambled (see CreditMemoParser). Grouped rather than 1:1
        // matched because two returns of the identical part at the identical
        // price are functionally interchangeable — which specific DR# a
        // given credit-line row pairs to doesn't matter, only that the
        // counts reconcile.
        $resolvedByKey = [];
        foreach ($resolvedByRef as $ref => $line) {
            $key = ($line->part_number_base ?? '').'|'.$line->line_total_cents;
            $resolvedByKey[$key][] = $ref;
        }

        $pairs = [];
        $unpairedCreditLines = [];
        foreach ($parsed->lines as $creditLine) {
            $key = ($creditLine['part_number_base'] ?? '').'|'.abs($creditLine['amount_cents']);
            if (! empty($resolvedByKey[$key])) {
                $pairs[] = ['ref' => array_shift($resolvedByKey[$key]), 'credit_line' => $creditLine];
            } else {
                $unpairedCreditLines[] = $creditLine;
            }
        }
        // Whatever's left in resolvedByKey are resolved DR# lines that never
        // found a same-(part, amount) credit-line row to pair with.
        $unpairedDrRefs = array_merge([], ...array_values($resolvedByKey));

        $document = VendorDocument::firstOrNew([
            'supplier' => PartsSupplier::Mygrant->value,
            'document_number' => $parsed->documentNumber,
        ]);
        $document->type = VendorDocumentType::CreditMemo->value;
        $document->document_date = $parsed->documentDate;
        // A credit memo has no "billing week" of its own to cut against — it
        // IS the document, dated the day it was issued.
        $document->cut_date = $parsed->documentDate;
        $document->settle_date = BillingCycle::settleDate($parsed->documentDate);
        $document->net_amount_cents = $parsed->netAmountCents;
        $document->line_count = count($parsed->lines);
        $document->source = 'pdf';

        $warnings = [];
        if ($unresolvedDrRefs) {
            $warnings[] = 'Unresolved DR# reference(s) — no matching order_line found: '.implode(', ', $unresolvedDrRefs);
        }
        if ($unpairedCreditLines) {
            $labels = array_map(
                fn (array $l) => $l['part_number'].' @ '.Money::format($l['amount_cents']),
                $unpairedCreditLines
            );
            $warnings[] = 'Credit line(s) with no matching DR# reference (need manual pairing): '.implode('; ', $labels);
        }
        if ($unpairedDrRefs) {
            $warnings[] = 'DR# reference(s) resolved but not paired to any credit line (need manual pairing): '.implode(', ', $unpairedDrRefs);
        }
        $document->notes = $warnings ? implode(' | ', $warnings) : null;
        $document->save();

        foreach ($pairs as $pair) {
            $line = $resolvedByRef[$pair['ref']];
            $line->status = VendorLineStatus::Returned->value;
            $line->returned_at = now();
            $line->credit_document_id = $document->id;

            // A returned part whose line was already matched to a job is NOT
            // un-linked — mutating job_id here would erase which job the
            // part was originally for. Instead we leave job_id exactly as
            // it was and flag no_job_reason so the (next agent's) matching
            // UI can surface "this job's part came back" without us having
            // an opinion about what that should mean for the job itself.
            if ($line->job_id !== null) {
                $line->no_job_reason = 'returned_after_job_match';
            }

            $line->save();
        }

        return VendorImport::create([
            'supplier' => PartsSupplier::Mygrant->value,
            'kind' => 'credit_memo',
            'filename' => $filename,
            'uploaded_by' => $actor?->id,
            'lines_parsed' => count($parsed->lines),
            'lines_created' => 0, // a credit memo only ever mutates existing lines
            'lines_updated' => count($pairs),
            'documents_touched' => 1,
            'result' => 'ok',
            'error' => $warnings ? implode(' | ', $warnings) : null,
        ]);
    }

    private function failed(string $filename, ?Employee $actor, string $error): VendorImport
    {
        return VendorImport::create([
            'supplier' => PartsSupplier::Mygrant->value,
            'kind' => 'credit_memo',
            'filename' => $filename,
            'uploaded_by' => $actor?->id,
            'result' => 'failed',
            'error' => $error,
        ]);
    }
}
