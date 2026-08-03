<?php

use App\Enums\MessageType;
use App\Enums\TemplateKey;
use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

/**
 * Repairs owner-edited template copy that shipped with real defects, and moves
 * the STOP opt-out onto the review drip only.
 *
 * The bodies live in the DB (the owner edits them in Message Templates), so the
 * fixes have to be data, not seeder copy. Every edit is a targeted str_replace
 * against the exact live body and a no-op when the phrase isn't found, so the
 * migration is idempotent and can't clobber a later rewording.
 *
 * The defects:
 *  - review_request_1 used {technician_name}, which is not a merge var — the
 *    renderer blanks unknown tokens, so every send read "One small favor:
 *    worked on your vehicle today". The var is {technician}. It also said "If
 *    HE earned it", which misgenders the female techs on the crew, and
 *    "we received your payment went through" (two phrasings collided).
 *  - review_request_2: "Of you have a spare moment" → "If".
 *  - review_voicemail: "still great after with uour {vehicle}" → "with your".
 *  - reminder_night_before: "tomorrow at {appointment_time}" double-prepositions
 *    ({appointment_time} already renders "between 9am–11am" / "at 8am").
 *
 * Opt-out: customers may only opt out of REVIEW texts, never transactional
 * ones, so the STOP line is added to every review body that lacks it and
 * stripped from the reminders (whose Spanish rows carried it). See
 * TemplateKey::requiresOptOutLanguage(), now enforced in MessageTemplateForm.
 */
return new class extends Migration
{
    private const OPT_OUT = [
        'en' => 'Reply STOP to opt out.',
        'es' => 'Responde STOP para dejar de recibir mensajes.',
    ];

    public function up(): void
    {
        // --- 1. The blank technician name + its two copy bugs. ---
        $this->replace('review_request_1', 'en', [
            '{technician_name}' => '{technician}',
            'Just confirming we received your payment went through' => 'Just confirming your payment went through',
            'If he earned it, 30 seconds here would make his week' => 'If they earned it, 30 seconds here would make their week',
        ]);

        // The Spanish row never mentioned the tech at all — it was still the
        // original generic ask while the English copy was rewritten around the
        // "mention our tech by name" hook. Mirror the English so both languages
        // make the same request.
        $this->setBody('review_request_1', 'es', implode("\n", [
            'Hola {first_name},',
            '',
            'Solo confirmamos que tu pago se procesó — ¡todo listo, y gracias de nuevo por tu preferencia!',
            '',
            'Un pequeño favor: {technician} trabajó en tu vehículo hoy, y cuando los clientes mencionan a '
                .'nuestros técnicos por su nombre en una reseña de Google, los reconocemos y los premiamos por '
                .'ello. Si se lo ganó, 30 segundos aquí le alegrarían la semana: {google_review_url}',
        ]));

        // --- 2. Straight typos. ---
        $this->replace('review_request_2', 'en', [
            'Of you have a spare moment' => 'If you have a spare moment',
        ]);

        $this->replace('review_voicemail', 'en', [
            'everything is still great after with uour {vehicle}' => 'everything is still great with your {vehicle}',
        ]);

        // --- 3. "See you tomorrow at between 9am–11am". ---
        $this->replace('reminder_night_before', 'en', [
            "We'll see you tomorrow at {appointment_time}" => "We'll see you tomorrow {appointment_time}",
        ]);

        // --- 4. SMS has no markdown; the asterisks were sending literally. ---
        $this->replace('review_voicemail', 'es', [
            'Responde **STOP**' => 'Responde STOP',
        ]);

        // --- 5. Opt-out belongs to the review drip, and nowhere else. ---
        foreach (TemplateKey::cases() as $key) {
            foreach (['en', 'es'] as $language) {
                $key->messageType() === MessageType::Review
                    ? $this->addOptOut($key, $language)
                    : $this->stripOptOut($key, $language);
            }
        }
    }

    public function down(): void
    {
        // Copy repair only — the previous bodies were broken, so there is
        // nothing worth restoring.
    }

    /** Apply search→replace pairs to a row, skipping phrases already gone. */
    private function replace(string $key, string $language, array $pairs): void
    {
        $row = $this->row($key, $language);
        if ($row === null) {
            return;
        }

        $body = $row->body;
        foreach ($pairs as $search => $replacement) {
            if (str_contains($body, $search)) {
                $body = str_replace($search, $replacement, $body);
            }
        }

        if ($body !== $row->body) {
            $row->update(['body' => $body]);
        }
    }

    private function setBody(string $key, string $language, string $body): void
    {
        $this->row($key, $language)?->update(['body' => $body]);
    }

    /** Append the opt-out sentence unless the body already invites a STOP. */
    private function addOptOut(TemplateKey $key, string $language): void
    {
        $row = $this->row($key->value, $language);
        if ($row === null || stripos($row->body, 'STOP') !== false) {
            return;
        }

        $row->update(['body' => rtrim($row->body)."\n\n".self::OPT_OUT[$language]]);
    }

    /** Remove the opt-out sentence from a template that must not advertise it. */
    private function stripOptOut(TemplateKey $key, string $language): void
    {
        $row = $this->row($key->value, $language);
        if ($row === null) {
            return;
        }

        $body = trim(str_replace(array_values(self::OPT_OUT), '', $row->body));
        // Collapse the blank line the removal leaves behind.
        $body = (string) preg_replace("/\n{3,}/", "\n\n", $body);

        if ($body !== $row->body) {
            $row->update(['body' => $body]);
        }
    }

    private function row(string $key, string $language): ?MessageTemplate
    {
        return MessageTemplate::query()
            ->where('key', $key)
            ->where('language', $language)
            ->first();
    }
};
