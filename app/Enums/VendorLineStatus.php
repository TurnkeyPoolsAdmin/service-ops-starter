<?php

namespace App\Enums;

/**
 * Where a vendor_document_lines row stands in Mygrant's own pickup/billing
 * flow (nothing to do with job-matching — see match_status for that).
 *
 *  - PendingPickup — confirmed at the counter but never picked up, so it
 *    never billed (Line_Total was 0 on the order-history export). A later
 *    import flips it to Billed once it's actually picked up and priced.
 *  - Billed        — has a real Line_Total, attached to an invoice document
 *    (or the current open/uninvoiced period).
 *  - Returned      — a later credit memo credited this exact line back.
 */
enum VendorLineStatus: string
{
    case PendingPickup = 'pending_pickup';
    case Billed = 'billed';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::PendingPickup => 'Pending pickup',
            self::Billed => 'Billed',
            self::Returned => 'Returned',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PendingPickup => 'warning',
            self::Billed => 'success',
            self::Returned => 'gray',
        };
    }
}
