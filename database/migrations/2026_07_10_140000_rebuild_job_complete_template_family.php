<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

/**
 * Corrects 28af2e6's mistaken assumption: the real "on job completion" text
 * customers get is `job_complete` (NOT the thin payment_zelle/payment_venmo
 * one-liners that migration invented). Renames `job_complete`'s NAME only
 * (never its body — the live prod copy must survive this) to
 * "Job Complete - Zelle", clones that same live body onto three new
 * "Job Complete - …" templates (Venmo/Cash/Check) for the owner to reword,
 * and builds a fourth Credit Card variant that swaps the Zelle/"prepare a
 * payment link" paragraphs for the real {payment_link}. Retires the thin
 * payment_zelle/payment_venmo/payment_cash/payment_check templates and
 * restores payment_link's name (28af2e6 wrongly relabeled it "Job Complete -
 * Credit Card" — it's the standalone "Text card payment link" action).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Read the LIVE prod body — never hardcode a stale copy — falling back
        // to the seeder's default only if the row is somehow missing.
        $base = MessageTemplate::where('key', 'job_complete')->value('body')
            ?? "Hi {first_name}, \n\nOur technician just confirmed your glass service is completed.  Please let us know if there were any issues.\n\nPlease note: if our technician left any tape on the glass after installation, please leave it on for 48 hours.  The tape helps support the glass while the adhesive cures and allows everything to settle properly. Removing or moving it too early can affect the fitment and will void our installation warranty.\n\nReminder the total amount due is {quote}.  Payment can be made by Zelle to  payments@example.com (Account Name: YOUR BUSINESS LLC).\n\nIf you’d prefer to pay with debit card or credit card (with a 3% processing fee) instead, just let us know and we'll prepare a payment link for you.\n\nThank you for choosing {location_name}!";

        // Rename job_complete's NAME only — body is untouched.
        MessageTemplate::where('key', 'job_complete')->update(['name' => 'Job Complete - Zelle']);

        foreach (self::cloneTemplates() as $key => $name) {
            MessageTemplate::firstOrCreate(
                ['key' => $key],
                ['name' => $name, 'body' => $base, 'channel' => 'sms', 'active' => true],
            );
        }

        $cardBody = self::buildCardVariant($base);
        MessageTemplate::firstOrCreate(
            ['key' => 'job_complete_credit_card'],
            ['name' => 'Job Complete - Credit Card', 'body' => $cardBody, 'channel' => 'sms', 'active' => true],
        );

        // Retire the thin one-liners 28af2e6 introduced.
        MessageTemplate::whereIn('key', ['payment_zelle', 'payment_venmo', 'payment_cash', 'payment_check'])->delete();

        // 28af2e6 wrongly renamed payment_link to "Job Complete - Credit Card" —
        // restore its real name (it's the standalone "Text card payment link" action).
        MessageTemplate::where('key', 'payment_link')->update(['name' => 'Card payment link (Stripe)']);
    }

    public function down(): void
    {
        MessageTemplate::whereIn('key', [
            'job_complete_credit_card', 'job_complete_venmo', 'job_complete_cash', 'job_complete_check',
        ])->delete();

        MessageTemplate::where('key', 'job_complete')->update(['name' => 'Job complete']);
    }

    private static function cloneTemplates(): array
    {
        return [
            'job_complete_venmo' => 'Job Complete - Venmo',
            'job_complete_cash' => 'Job Complete - Cash',
            'job_complete_check' => 'Job Complete - Check',
        ];
    }

    /**
     * Build the Credit Card variant deterministically: drop every line whose
     * lowercase form mentions "zelle" or "payment link" (the Zelle-specific
     * instructions), and insert the card-payment paragraph at the position of
     * the first dropped line (or just before the final line if nothing was
     * dropped), so the reminder-to-pay paragraph lands where Zelle's used to.
     */
    private static function buildCardVariant(string $base): string
    {
        $lines = explode("\n", $base);
        $cardParagraph = 'Reminder the total amount due is {payment_total} (includes a 3% card processing fee). '
            .'You can pay securely by card here: {payment_link}';

        $kept = [];
        $insertAt = null;
        foreach ($lines as $line) {
            $lower = strtolower($line);
            if (str_contains($lower, 'zelle') || str_contains($lower, 'payment link')) {
                if ($insertAt === null) {
                    $insertAt = count($kept);
                }

                continue;
            }
            $kept[] = $line;
        }

        if ($insertAt === null) {
            $insertAt = max(0, count($kept) - 1);
        }

        array_splice($kept, $insertAt, 0, [$cardParagraph]);

        // Dropping a paragraph leaves its blank-line separators behind; collapse
        // any run of blank lines back to the single blank line the copy uses.
        $result = preg_replace("/\n{3,}/", "\n\n", implode("\n", $kept));

        if (! str_contains($result, '{payment_link}')) {
            throw new \RuntimeException('job_complete_credit_card body is missing {payment_link} — refusing to seed a text that can never queue.');
        }

        return $result;
    }
};
