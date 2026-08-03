<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

/**
 * The "Send receipt" text now links to a server-generated PDF instead of
 * spelling out the amount/method/date inline (see "Receipts" in CLAUDE.md).
 * The seeder edit alone never reaches an already-live DB row (same gotcha as
 * 2026_07_16_130000 / 2026_07_17_140000), so this rewrites the two live
 * `receipt` rows directly. Rewrites ANY receipt body missing {receipt_link}
 * (not just an exact old-seeder match): the key's required var is now
 * {receipt_link}, so a body without it fails the required-var gate and the
 * "Send receipt" action silently disappears — an owner-edited copy of the old
 * text is just as broken as the seeded one. Idempotent: a row already
 * carrying {receipt_link} (including a later owner edit of the new copy) is
 * never touched.
 */
return new class extends Migration
{
    private const NEW_EN = "Hi {first_name}, thanks for your business! Here's your receipt from {location_name}: {receipt_link}";

    // Deliberately accent-free (unlike most Spanish copy) so it stays GSM-7
    // alongside the long signed link - see App\Support\GsmText / CLAUDE.md.
    private const NEW_ES = 'Hola {first_name}, gracias por tu preferencia! Aqui esta tu recibo de {location_name}: {receipt_link}';

    public function up(): void
    {
        $this->rewrite('en', self::NEW_EN);
        $this->rewrite('es', self::NEW_ES);
    }

    public function down(): void
    {
        // Copy change only - nothing worth restoring (the old body required
        // {amount_paid}, which the app no longer passes to this template).
    }

    private function rewrite(string $language, string $newBody): void
    {
        MessageTemplate::query()
            ->where('key', 'receipt')
            ->where('language', $language)
            ->where('body', 'not like', '%{receipt_link}%')
            ->update(['body' => $newBody]);
    }
};
