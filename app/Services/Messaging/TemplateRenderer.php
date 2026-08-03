<?php

namespace App\Services\Messaging;

use App\Enums\Language;
use App\Enums\TemplateKey;
use App\Models\Job;
use App\Models\MessageTemplate;
use App\Support\GsmText;
use App\Support\Money;
use App\Support\PhoneNumber;
use App\Support\RequestedDay;
use App\Support\Timezone;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Renders SMS bodies from message_templates rows, substituting {merge_vars}.
 * Missing variables render as empty strings (graceful), but templates declare
 * required vars (TemplateKey::requiredVars()) which are validated before a
 * message is allowed into the outbox.
 *
 * Every template has one row per language (unique on key+language). The caller
 * passes the recipient's language - the customer's for customer texts, the
 * tech's for tech texts - and a missing or inactive row falls back to English,
 * so a template nobody has translated yet still sends.
 */
class TemplateRenderer
{
    public const SUPPORTED_VARS = [
        'first_name', 'salesperson', 'location_name',
        'preferred_date_time', 'scheduled_date_time', 'appointment_window', 'appointment_time',
        'quote', 'vehicle', 'technician', 'dispatch',
        'city', 'part_number', 'job_type',
        // TECH-facing detail. {city} deliberately stays the coarse read for
        // outreach to a tech who hasn't taken the job yet; these two are the
        // full picture, for the tech who HAS it (see TechJobConfirmation).
        'customer_phone', 'service_address',
        'google_review_url', 'yelp_review_url',
        'payment_link', 'payment_total',
        'zelle_details', 'venmo_details',
        'amount_paid', 'payment_method', 'payment_date', 'receipt_link',
        'warranty_date_time',
        // The post-welcome scheduling ask (App\Support\SchedulingAsk). The first
        // two resolve from the job's own columns; the day phrasings are computed
        // per-send and passed as extra vars.
        'requested_day', 'requested_time', 'earliest_day', 'earlier_window',
        // AI scheduling negotiation (docs/ai-scheduling-spec.md) — all computed
        // per-send by App\Services\AiScheduling and passed as extra vars; none
        // of these are derivable from the job's own columns the way the vars
        // above are.
        'slot_phrase', 'constraint_phrase', 'load_note', 'tech_proposed_time',
        // The parts source. Resolves from the job, but the AI warehouse-set
        // text passes it as an extra var instead: it is confirmed back in the
        // same breath as it is written, before the job is reloaded.
        'warehouse',
    ];

    /** Replace {tokens} in a body with the provided values. Unknown tokens → ''. */
    public function render(string $body, array $vars): string
    {
        $rendered = preg_replace_callback('/\{(\w+)\}/', function ($m) use ($vars) {
            $key = $m[1];

            return isset($vars[$key]) ? (string) $vars[$key] : '';
        }, $body);

        // Last line of defense: strip smart quotes/dashes (from an un-migrated
        // template row OR a pasted merge value like a customer name) so no
        // outbound text is forced into UCS-2 encoding and silently dropped by a
        // carrier. See App\Support\GsmText.
        return GsmText::normalize($rendered);
    }

    /**
     * Build the full merge-variable map for a job. $dispatchName is the dispatcher
     * acting on the send ("Nico"); when omitted it falls back to whoever created
     * the job, so automated sends still resolve {dispatch} sensibly. $language
     * localizes the date phrasings, so a Spanish body never carries an English
     * appointment date.
     */
    public function varsForJob(Job $job, ?string $dispatchName = null, ?Language $language = null): array
    {
        $job->loadMissing(['customer', 'entity', 'bookingSalesperson', 'technician', 'createdBy']);

        $vehicle = trim(implode(' ', array_filter([
            $job->vehicle_year,
            $job->vehicle_make,
            $job->vehicle_model,
        ]))) ?: ($job->vehicle_raw ?? '');

        $scheduled = self::formatScheduled($job, $language);

        return [
            'first_name' => $job->customer?->first_name ?? '',
            // Snapshot-first: a sales-dashboard lead names a rep who isn't an
            // employees row here (Job::bookingSalespersonName()).
            'salesperson' => $job->bookingSalespersonName() ?? '',
            'location_name' => $job->entity?->location_name ?? '',
            // The customer's loosely-parsed request ("Thursday afternoon"), shown
            // in the welcome before we've pinned an exact time.
            'preferred_date_time' => $job->requested_daytime_raw ?? '',
            // The same request, split: the day spelled out and the time-of-day
            // preference as a sentence tail ("in the morning", "at 2pm"). Used
            // by the scheduling-ask family, which overrides both with its own
            // freshly-computed values; these are the sensible standalone reads.
            'requested_day' => RequestedDay::spellOut($job->requested_day, $language) ?? '',
            'requested_time' => $job->timePreference()->phrase($job->requested_time, $language, $job->requestedAfterTime()),
            // The confirmed appointment, shown in confirmations + reminders. Reads
            // as a 2-hour arrival window ("Mon, July 6th between 8am-10am") unless
            // the job is flagged exact-time, then "...at 8am". See formatScheduled().
            'scheduled_date_time' => $scheduled,
            // Just the time portion of that window ("8am-10am", or "8am" when the
            // job is exact-time) for templates that phrase the date themselves.
            'appointment_window' => self::formatWindow($job),
            // Same window, but prefixed with the natural preposition so a template
            // can drop it straight after "tomorrow": "between 8am-10am" for a
            // window, or "at 8am" when the job is exact-time. '' until scheduled.
            'appointment_time' => self::formatWindowPhrase($job, $language),
            // Back-compat alias: older templates may still use {date_time}. Not
            // advertised in SUPPORTED_VARS; resolves to the scheduled time.
            'date_time' => $scheduled,
            'quote' => $job->quote_amount_cents !== null ? Money::format($job->quote_amount_cents) : '',
            'vehicle' => $vehicle,
            'technician' => $job->technician?->name ?? '',
            'dispatch' => $dispatchName ?? $job->createdBy?->name ?? '',
            // City only (never the street address) for tech outreach; falls back
            // to the parser's city when the job's own column wasn't filled.
            'city' => $job->city ?: ($job->parsed_payload_json['city'] ?? ''),
            // The full picture, for a tech who already has the job: where to go
            // and who to call when they get there.
            'customer_phone' => PhoneNumber::toDisplay($job->customer?->phone_e164) ?? '',
            'service_address' => $job->fullServiceAddress() ?? '',
            'part_number' => $job->part_number ?? '',
            'warehouse' => $job->warehouseLabel() ?? '',
            // Free-text glass position doubles as the "type of job" (windshield,
            // door glass, back glass, regulator, calibration, ...).
            'job_type' => $job->glass_position ?? '',
            // Per-location review links (Admin → Entities) for the review drip.
            'google_review_url' => $job->entity?->google_review_url ?? '',
            'yelp_review_url' => $job->entity?->yelp_review_url ?? '',
            // Stripe card-payment link + its surcharged total, set right before the
            // payment-link text is queued (JobLifecycle::createAndTextStripeInvoice).
            'payment_link' => $job->stripe_invoice_url ?? '',
            'payment_total' => $job->stripe_invoice_amount_cents !== null
                ? Money::format($job->stripe_invoice_amount_cents) : '',
            // Per-location Zelle/Venmo payment details (Admin → Entities).
            'zelle_details' => $job->entity?->zelle_details ?? '',
            'venmo_details' => $job->entity?->venmo_details ?? '',
            // Recorded customer payment, for the on-request receipt text. All
            // three are empty until a payment has been recorded on the job.
            'amount_paid' => $job->isPaid() && $job->amount_collected_cents !== null
                ? Money::format($job->amount_collected_cents) : '',
            'payment_method' => $job->isPaid() ? ($job->payment_method?->label() ?? '') : '',
            'payment_date' => $job->payment_received_at !== null
                ? Timezone::toDisplay($job->payment_received_at)->format('M j, Y') : '',
        ];
    }

    /**
     * The full confirmed appointment phrasing for {scheduled_date_time}: a friendly
     * date plus either a 2-hour arrival window ("Monday, July 6th between 8am-10am")
     * or, when the job is flagged exact-time, a single time ("...at 8am"). '' if unset.
     */
    private static function formatScheduled(Job $job, ?Language $language = null): string
    {
        if ($job->scheduled_at === null) {
            return '';
        }

        $start = Timezone::toDisplay($job->scheduled_at);

        return self::formatDate($start, $language).' '.self::formatWindowPhrase($job, $language);
    }

    /**
     * The same friendly date+window phrasing as {scheduled_date_time}, but for
     * an arbitrary visit time (used by the warranty return-visit text, whose
     * appointment lives on the warranty claim, not the job). '' if unset.
     */
    public static function formatVisit(
        ?CarbonInterface $utc,
        bool $exactTime,
        ?int $windowHours,
        ?Language $language = null,
    ): string {
        if ($utc === null) {
            return '';
        }

        $start = Timezone::toDisplay($utc);
        $date = self::formatDate($start, $language);

        if ($exactTime) {
            return $date.' '.self::atPhrase($language).' '.self::formatTime($start);
        }

        $hours = $windowHours ?: (int) config('glass.appointment_window_hours', 2);
        $end = $start->copy()->addHours($hours);

        return $date.' '.self::betweenPhrase(self::formatTime($start), self::formatTime($end), $language);
    }

    /**
     * Just the time portion of a job's window for {appointment_window}: "8am-10am"
     * (window end = start + the job's scheduled_window_hours override, else
     * config('glass.appointment_window_hours')), or the single "8am" when the job
     * is exact-time. '' unless scheduled. On-the-hour times drop ":00" for a
     * tidier read ("8am" not "8:00am"). Clock times read the same in both
     * languages, so this one takes no language.
     */
    public static function formatWindow(Job $job): string
    {
        if ($job->scheduled_at === null) {
            return '';
        }

        $start = Timezone::toDisplay($job->scheduled_at);

        if ($job->scheduled_exact_time) {
            return self::formatTime($start);
        }

        return self::formatTime($start).'-'.self::formatTime(self::windowEnd($job, $start));
    }

    /**
     * The window with its natural preposition for {appointment_time}: "at 8am"
     * for an exact-time job, "between 8am-10am" for a window - "a las 8am" /
     * "entre 8am y 10am" in Spanish. '' unless scheduled.
     */
    public static function formatWindowPhrase(Job $job, ?Language $language = null): string
    {
        if ($job->scheduled_at === null) {
            return '';
        }

        $start = Timezone::toDisplay($job->scheduled_at);

        if ($job->scheduled_exact_time) {
            return self::atPhrase($language).' '.self::formatTime($start);
        }

        return self::betweenPhrase(
            self::formatTime($start),
            self::formatTime(self::windowEnd($job, $start)),
            $language,
        );
    }

    /** End of the arrival window: start + the job's override, else the configured span. */
    private static function windowEnd(Job $job, CarbonInterface $start): CarbonInterface
    {
        $hours = $job->scheduled_window_hours ?: (int) config('glass.appointment_window_hours', 2);

        return $start->copy()->addHours($hours);
    }

    /** "Monday, July 6th" / "lunes, 6 de julio". */
    private static function formatDate(CarbonInterface $t, ?Language $language = null): string
    {
        return $language === Language::Spanish
            ? $t->locale('es')->translatedFormat('l, j \d\e F')
            : $t->format('l, F jS');
    }

    /** The preposition introducing a single exact time. */
    private static function atPhrase(?Language $language): string
    {
        return $language === Language::Spanish ? 'a las' : 'at';
    }

    /** The arrival window, spelled out in the recipient's language. */
    private static function betweenPhrase(string $from, string $to, ?Language $language): string
    {
        return $language === Language::Spanish
            ? "entre {$from} y {$to}"
            : "between {$from}-{$to}";
    }

    /**
     * The {warehouse} value for the tech's job card: the parts source, or a
     * plain statement that nobody has picked one yet. It stands in rather than
     * leaving the line blank, so the tech can see the field is open and not
     * think we forgot it. Passed as an extra var by every caller that sends
     * the card (JobLifecycle and AI scheduling), since the bare job-resolved
     * {warehouse} has no fallback — the AI's warehouse-set text needs it empty.
     */
    public static function warehouseVarForTech(Job $job, ?Language $language): string
    {
        return $job->warehouseLabel() ?: self::warehousePendingPhrase($language);
    }

    /** Just the fallback half of warehouseVarForTech(), in the tech's language. */
    public static function warehousePendingPhrase(?Language $language): string
    {
        return $language === Language::Spanish
            ? 'Esperando a que escojas el almacen'
            : 'Waiting on you to select warehouse';
    }

    /** A single clock time, dropping ":00" on the hour ("8am" / "8:30am"). */
    private static function formatTime(CarbonInterface $t): string
    {
        return $t->minute === 0 ? $t->format('ga') : $t->format('g:ia');
    }

    /**
     * The row to send for a template in $language, falling back to English when
     * that language has no row or its row is switched off. This is what lets a
     * template stay untranslated without blocking the send.
     *
     * @throws TemplateRenderException when neither the requested language nor English can be sent.
     */
    public function resolveTemplate(TemplateKey $key, ?Language $language = null): MessageTemplate
    {
        $rows = MessageTemplate::query()->forKey($key)->get();

        if ($rows->isEmpty()) {
            throw new TemplateRenderException("Template [{$key->value}] does not exist.");
        }

        $sendable = fn (Language $wanted) => $rows->first(
            fn (MessageTemplate $row) => $row->language === $wanted && $row->active,
        );

        $template = $sendable($language ?? Language::English) ?? $sendable(Language::English);

        if (! $template) {
            throw new TemplateRenderException("Template [{$key->value}] is inactive.");
        }

        return $template;
    }

    /**
     * Render a template (by key) for a job. Validates required vars. $extraVars
     * layers caller-supplied values (e.g. {warranty_date_time}) over the job's
     * own merge map - they count toward the required-var check too. $language is
     * the recipient's, and picks which translation of the body goes out.
     *
     * @throws TemplateRenderException when the template is missing/inactive or a required var is empty.
     */
    public function renderForJob(
        TemplateKey $key,
        Job $job,
        ?string $dispatchName = null,
        array $extraVars = [],
        ?Language $language = null,
    ): string {
        $template = $this->resolveTemplate($key, $language);

        // Date phrasing follows the language we actually resolved, not the one
        // asked for - an untranslated template falls back to an English body,
        // which would read oddly with a Spanish date in it.
        $vars = array_merge($this->varsForJob($job, $dispatchName, $template->language), $extraVars);

        $missing = array_filter(
            $key->requiredVars(),
            fn ($required) => blank($vars[$required] ?? null),
        );

        if (! empty($missing)) {
            throw new TemplateRenderException(
                "Template [{$key->value}] is missing required vars: ".implode(', ', $missing).'.'
            );
        }

        return Str::of($this->render($template->body, $vars))->trim()->toString();
    }
}
