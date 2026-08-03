<?php

namespace App\Services\Vendors\Mygrant;

use App\Enums\PartsSupplier;
use App\Enums\VendorDocumentType;
use App\Enums\VendorLineStatus;
use App\Models\Employee;
use App\Models\VendorDocument;
use App\Models\VendorDocumentLine;
use App\Models\VendorImport;
use App\Models\Warehouse;
use App\Support\Money;
use App\Support\PartNumber;
use App\Support\Timezone;
use Carbon\Carbon;

/**
 * Ingests Mygrant's "order history" CSV export — a rolling window (currently
 * ~2 weeks) of every order line on our account, confirmed or not, billed or
 * not. Re-uploading a later export is expected (the window overlaps prior
 * uploads), so everything here is an UPSERT keyed on `order_line` — never a
 * duplicate, never a delete (a partial window can't tell us a line vanished).
 *
 * ⚠️ STRUCTURAL INVARIANT: a numbered invoice document (non-null
 * document_number) may ONLY ever contain lines whose Invoice_Number equals
 * that document's number. A line with no Invoice_Number has not been
 * invoiced — full stop — and must never join a numbered document, no matter
 * how plausible its computed cut date looks. This is enforced by
 * CONSTRUCTION: resolveNumberedInvoiceDocument() is the only path that ever
 * sets document_number, and resolveOpenDocument() (the only path an
 * uninvoiced line can take) always filters ->whereNull('document_number'),
 * so it structurally cannot return a numbered document even if one already
 * sits on the same cut date.
 *
 * This replaced a real bug: an earlier version fell back to Order_Date for
 * cut-date math whenever Confirmed_Date was blank, and attached the result
 * straight to whatever document already owned that cut date — including an
 * already-invoiced, already-CHARGED one. A single orphan line with a blank
 * Confirmed_Date silently inflated a closed invoice's net by its own amount,
 * which would have poisoned the (separate) charge reconciler's "are we
 * short a credit memo?" signal — the single most important number in this
 * feature — permanently and undetectably (the corruption is internally
 * consistent: the document's net still equals the sum of ITS lines, it's
 * just that one of those lines never should have been there).
 *
 * The fix: an uninvoiced line's cut date is only ever trusted when
 * Confirmed_Date itself is present. When it's blank, we don't know when
 * Mygrant will actually consider the part picked up, so instead of guessing
 * a historical week, the line is parked in the CURRENT open period and
 * flagged (needs_confirmation_date) for a human to chase down. Rolling
 * forward is the safe direction — a line that lands on a later invoice than
 * it "should" self-corrects the moment a real Confirmed_Date/Invoice_Number
 * shows up in a later export, whereas one absorbed into an already-closed,
 * already-charged invoice corrupts a settled week forever.
 */
class OrderHistoryImporter
{
    public function import(string $csvContents, ?Employee $actor, string $filename): VendorImport
    {
        $rows = self::parseCsv($csvContents);

        $linesParsed = 0;
        $linesCreated = 0;
        $linesUpdated = 0;
        $linesNeedingConfirmationDate = 0;
        $touchedDocumentIds = [];

        foreach ($rows as $row) {
            $orderLine = trim((string) ($row['Order_Line'] ?? ''));
            if ($orderLine === '') {
                // Malformed/blank row (e.g. a trailing blank line) — nothing
                // to key an upsert on, so there's nothing safe to do with it.
                continue;
            }

            $linesParsed++;

            $orderDate = self::parseDate($row['Order_Date'] ?? null);
            $confirmedDateRaw = self::parseDate($row['Confirmed_Date'] ?? null);
            $needsConfirmationDate = $confirmedDateRaw === null;
            if ($needsConfirmationDate) {
                $linesNeedingConfirmationDate++;
            }

            $lineTotalCents = Money::centsFromDecimalString($row['Line_Total'] ?? '0');
            $unitPriceCents = Money::centsFromDecimalString($row['Unit_Price'] ?? '0');

            $partNumberRaw = trim((string) ($row['Part_Number'] ?? ''));
            $vehicle = PartDescriptionParser::parseVehicle($row['Part_Description'] ?? null);

            $invoiceNumber = self::blankToNull($row['Invoice_Number'] ?? null);
            $invoiceDate = self::parseDate($row['Invoice_Date'] ?? null);

            $warehouseCode = self::blankToNull($row['MGC_Warehouse_Number'] ?? null);
            $warehouseId = $this->resolveWarehouseId($warehouseCode, $row['MGC_Warehouse_Name'] ?? null);

            $line = VendorDocumentLine::firstOrNew(['order_line' => $orderLine]);
            $wasNew = ! $line->exists;

            $line->fill([
                'supplier' => PartsSupplier::Mygrant->value,
                'order_number' => trim((string) ($row['Order_Number'] ?? '')),
                'order_date' => $orderDate,
                'confirmed_date' => $confirmedDateRaw,
                'needs_confirmation_date' => $needsConfirmationDate,
                'customer_po' => self::blankToNull($row['Customer_PO'] ?? null),
                'ordered_by' => self::blankToNull($row['Ordered_By'] ?? null),
                'warehouse_code' => $warehouseCode,
                'warehouse_id' => $warehouseId,
                'part_number' => $partNumberRaw,
                'part_number_base' => PartNumber::normalizeBase($partNumberRaw),
                'part_description' => self::blankToNull($row['Part_Description'] ?? null),
                'vehicle_years_from' => $vehicle['years_from'],
                'vehicle_years_to' => $vehicle['years_to'],
                'vehicle_make' => $vehicle['make'],
                'vehicle_model' => $vehicle['model'],
                'quantity_ordered' => (float) ($row['Quantity_Ordered'] ?? 0),
                'quantity_delivered' => (float) ($row['Quantity_Delivered'] ?? 0),
                'unit_price_cents' => $unitPriceCents,
                'line_total_cents' => $lineTotalCents,
            ]);

            if ($lineTotalCents === 0) {
                // Confirmed at the counter, never picked up — never billed.
                // Kept (not dropped) so a later import can flip it to billed,
                // and so a future "part never arrived" alert can catch it.
                $line->status = VendorLineStatus::PendingPickup->value;
                $line->vendor_document_id = null;
                $line->invoice_number = null;
                $line->invoice_date = null;
                $line->cut_date = null;
            } elseif ($invoiceNumber !== null) {
                // Genuinely invoiced. Trust Invoice_Date for the cut date —
                // it's Mygrant's own stated figure, not a computed guess (and
                // per the verified fact, Invoice_Date always equals the
                // computed cut_date anyway), falling back to computing it
                // from Confirmed_Date only if Invoice_Date is somehow absent.
                $cutDate = $invoiceDate
                    ?? ($confirmedDateRaw ? BillingCycle::cutDate($confirmedDateRaw) : BillingCycle::cutDate(Timezone::now()->toDateString()));
                $document = $this->resolveNumberedInvoiceDocument($cutDate, $invoiceNumber, $invoiceDate);

                $line->status = VendorLineStatus::Billed->value;
                $line->vendor_document_id = $document->id;
                $line->invoice_number = $invoiceNumber;
                $line->invoice_date = $invoiceDate;
                $line->cut_date = $cutDate;

                $touchedDocumentIds[] = $document->id;
            } else {
                // Billed (Line_Total > 0) but NOT yet invoiced — the current
                // running-total period. Never attaches to a numbered
                // document (see class doc invariant), even when a numbered
                // invoice already happens to sit on the same cut date (the
                // rolling CSV window can straddle an already-closed week).
                $cutDate = $confirmedDateRaw
                    ? BillingCycle::cutDate($confirmedDateRaw)
                    : BillingCycle::cutDate(Timezone::now()->toDateString());
                $document = $this->resolveOpenDocument($cutDate);

                $line->status = VendorLineStatus::Billed->value;
                $line->vendor_document_id = $document->id;
                $line->invoice_number = null;
                $line->invoice_date = null;
                $line->cut_date = $cutDate;

                $touchedDocumentIds[] = $document->id;
            }

            $line->save();
            $wasNew ? $linesCreated++ : $linesUpdated++;
        }

        // Recompute every touched document's totals from its lines rather
        // than accumulating during the loop above — simpler, and correct
        // even when the same document is touched by several rows in one
        // import (or by a re-import that changes an already-billed line).
        $touchedDocumentIds = array_unique($touchedDocumentIds);
        foreach ($touchedDocumentIds as $documentId) {
            $document = VendorDocument::find($documentId);
            if (! $document) {
                continue;
            }

            // Deliberately includes lines that were later RETURNED — a
            // return is a separate credit-memo document, never a mutation of
            // the original invoice. This is exactly what makes an invoice's
            // net_amount_cents tie to its own PDF to the penny even after a
            // return: the invoice billed the full amount; the credit memo
            // nets against it as its own line item.
            $document->update([
                'net_amount_cents' => (int) $document->lines()->sum('line_total_cents'),
                'line_count' => $document->lines()->count(),
            ]);
        }

        return VendorImport::create([
            'supplier' => PartsSupplier::Mygrant->value,
            'kind' => 'order_history',
            'filename' => $filename,
            'uploaded_by' => $actor?->id,
            'lines_parsed' => $linesParsed,
            'lines_created' => $linesCreated,
            'lines_updated' => $linesUpdated,
            'documents_touched' => count($touchedDocumentIds),
            'lines_needing_confirmation_date' => $linesNeedingConfirmationDate,
            'result' => 'ok',
        ]);
    }

    /**
     * Attach a genuinely-invoiced line to ITS numbered document — promoting
     * the existing OPEN document for the same cut date in place when this is
     * the first row to reveal the invoice number (the normal path: the
     * week's running total finally gets its real number), else reusing an
     * existing document already carrying this exact invoice number (a
     * re-import), else starting a brand-new numbered document.
     *
     * This is the ONLY method in this class that ever sets document_number —
     * see the class doc invariant.
     *
     * ⚠️ An open document is only eligible for promotion if NONE of its
     * current lines are needs_confirmation_date-flagged. Without this guard
     * the bug this class fixed can sneak back in through a side door: an
     * orphan line (blank Confirmed_Date) processed earlier in the SAME
     * import run creates/claims the open document for "today's" cut date;
     * if that cut date happens to equal a real invoice's cut date, a later
     * row in the same run revealing that real invoice number would
     * otherwise happily promote the orphan's document — merging the
     * untrustworthy line into the numbered invoice anyway. Excluding any
     * open document that already holds a flagged line means promotion only
     * ever happens for documents built entirely from trustworthy
     * (Confirmed_Date-bearing) lines; a tainted open document is left open
     * and a fresh numbered document is created alongside it instead.
     */
    private function resolveNumberedInvoiceDocument(string $cutDate, string $invoiceNumber, ?string $invoiceDate): VendorDocument
    {
        $document = VendorDocument::where('supplier', PartsSupplier::Mygrant->value)
            ->where('type', VendorDocumentType::Invoice->value)
            ->where('cut_date', $cutDate)
            ->whereNull('document_number')
            ->whereDoesntHave('lines', fn ($q) => $q->where('needs_confirmation_date', true))
            ->first()
            ?? VendorDocument::where('supplier', PartsSupplier::Mygrant->value)
                ->where('document_number', $invoiceNumber)
                ->first()
            ?? new VendorDocument([
                'supplier' => PartsSupplier::Mygrant->value,
                'type' => VendorDocumentType::Invoice->value,
                'net_amount_cents' => 0,
            ]);

        $document->document_number = $invoiceNumber;
        $document->document_date = $invoiceDate;
        $document->cut_date = $cutDate;
        $document->settle_date = BillingCycle::settleDate($cutDate);
        $document->source = $document->source ?: 'order_history';
        $document->save();

        return $document;
    }

    /**
     * Attach an UNINVOICED (no Invoice_Number) line to the open running-total
     * document for its cut date. Always filters ->whereNull('document_number')
     * so this structurally CANNOT return a numbered document, even one that
     * already exists for this exact cut date — see the class doc invariant.
     * That means it's possible (and correct) for a cut date to end up with
     * two documents: a numbered one, plus a separate open one catching
     * stragglers the export hasn't invoiced yet.
     */
    private function resolveOpenDocument(string $cutDate): VendorDocument
    {
        $document = VendorDocument::where('supplier', PartsSupplier::Mygrant->value)
            ->where('type', VendorDocumentType::Invoice->value)
            ->where('cut_date', $cutDate)
            ->whereNull('document_number')
            ->first()
            ?? new VendorDocument([
                'supplier' => PartsSupplier::Mygrant->value,
                'type' => VendorDocumentType::Invoice->value,
                'source' => 'order_history',
                'net_amount_cents' => 0,
            ]);

        $document->cut_date = $cutDate;
        $document->settle_date = BillingCycle::settleDate($cutDate);
        $document->save();

        return $document;
    }

    private function resolveWarehouseId(?string $code, ?string $name): ?int
    {
        if ($code === null) {
            return null;
        }

        $warehouse = Warehouse::where('supplier', PartsSupplier::Mygrant->value)
            ->where('supplier_code', $code)
            ->first();

        if (! $warehouse) {
            $warehouse = Warehouse::create([
                'name' => $name ?: $code,
                'supplier' => PartsSupplier::Mygrant->value,
                'supplier_code' => $code,
                'active' => true,
            ]);
        }

        return $warehouse->id;
    }

    /**
     * Header-mapped CSV parsing — never positional, so a supplier reordering
     * their export columns doesn't silently corrupt the import. Tolerant of
     * the trailing empty column (the header ends "...,Invoice_Date," with no
     * 22nd name) and of a data row with a different field count than the
     * header (padded/truncated to the header's length either way).
     *
     * @return array<int, array<string, string|null>>
     */
    public static function parseCsv(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents; // strip a UTF-8 BOM
        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];
        $lines = array_values(array_filter($lines, fn ($line) => trim($line) !== ''));

        if (empty($lines)) {
            return [];
        }

        $header = str_getcsv((string) array_shift($lines));
        while (! empty($header) && trim((string) end($header)) === '') {
            array_pop($header);
        }
        $columns = count($header);

        $rows = [];
        foreach ($lines as $line) {
            $fields = str_getcsv($line);
            $fields = array_slice(array_pad($fields, $columns, null), 0, $columns);
            $rows[] = array_combine($header, $fields);
        }

        return $rows;
    }

    private static function parseDate(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('n/j/Y g:i:s A', $raw)->toDateString();
        } catch (\Throwable) {
            try {
                return Carbon::parse($raw)->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }
    }

    private static function blankToNull(?string $raw): ?string
    {
        $raw = trim((string) $raw);

        return $raw === '' ? null : $raw;
    }
}
