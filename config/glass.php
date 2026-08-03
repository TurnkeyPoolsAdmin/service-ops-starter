<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Display / scheduling timezone
    |--------------------------------------------------------------------------
    |
    | All operator-facing times are shown in this timezone, and all "send at"
    | computations (reminders, reviews) are performed in this timezone before
    | being converted to UTC for storage. Stored timestamps are ALWAYS UTC
    | (app.timezone). Do not confuse this with config('app.timezone').
    |
    */
    'timezone' => env('APP_DISPLAY_TIMEZONE', 'America/Los_Angeles'),

    /*
    |--------------------------------------------------------------------------
    | Outbound SMS provider
    |--------------------------------------------------------------------------
    |
    | 'log'         => LogMessageProvider: records the outbox row + logs the body,
    |                  sends nothing. Default everywhere except production.
    | 'openphone'   => OpenPhoneProvider: real sends via Quo/OpenPhone.
    | 'podium'      => PodiumProvider: real sends through the Podium location number.
    | 'ringcentral' => RingCentralProvider: real sends from a RingCentral number.
    |
    | Real providers ('openphone'/'podium'/'ringcentral') only send when the app
    | runs in production AND outgoing texts are not paused. The active provider can
    | be switched at runtime via Admin → Integrations (setting `outgoing_provider`),
    | which overrides this config default.
    |
    */
    'message_provider' => env('MESSAGE_PROVIDER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Quo / OpenPhone inbox deep links
    |--------------------------------------------------------------------------
    |
    | Base of the Quo web inbox. We build a per-customer conversation link as
    | {inbox_base}/{phoneNumberId}/c/{conversationId} from the ids returned by
    | the Quo send response / webhooks, and store it on the customer to power
    | the job-view "Call" button and the Messaging "Custom" option.
    |
    */
    'quo' => [
        'inbox_base' => env('QUO_INBOX_BASE', 'https://my.quo.com/inbox'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Podium API (outbound send + sales-message capture)
    |--------------------------------------------------------------------------
    |
    | Host/version pinned here; OAuth credentials (client id/secret, refresh
    | token), location UID, and webhook secret live encrypted in the settings
    | table (Admin → Integrations → Podium). Outgoing texts route through the
    | one Podium location number, keeping the whole thread on a single number.
    |
    | `version` is sent as the `podium-version` header when non-empty — confirm
    | the value your Podium app requires before enabling Podium sends.
    |
    */
    'podium' => [
        'api_base' => env('PODIUM_API_BASE', 'https://api.podium.com'),
        'token_url' => env('PODIUM_TOKEN_URL', 'https://api.podium.com/oauth/token'),
        'authorize_url' => env('PODIUM_AUTHORIZE_URL', 'https://api.podium.com/oauth/authorize'),
        'version' => env('PODIUM_API_VERSION', ''),
        // Least-privilege: read+send messages and read locations (to discover the UID).
        'scopes' => 'read_messages write_messages read_locations',
    ],

    /*
    |--------------------------------------------------------------------------
    | RingCentral API (outbound send + inbound SMS capture)
    |--------------------------------------------------------------------------
    |
    | Server-to-server (JWT) auth: a long-lived JWT credential is exchanged for a
    | short-lived access token at {server}/restapi/oauth/token. The app client
    | id/secret, the JWT, the SMS-enabled sending number, the server URL, and the
    | webhook verification token all live encrypted in the settings table
    | (Admin → Integrations → RingCentral). No refresh-token rotation: each token
    | is minted fresh from the JWT, so there is nothing to expire silently.
    |
    | `server_url` defaults to production; set it to the devtest host in settings
    | to point at the sandbox. Paths below are stable across RingCentral versions.
    |
    */
    'ringcentral' => [
        // Default only — the live value is read from settings (sandbox-switchable).
        'server_url' => env('RINGCENTRAL_SERVER_URL', 'https://platform.ringcentral.com'),
        'token_path' => '/restapi/oauth/token',
        // ~ resolves to the authenticated extension/account for the JWT's user.
        'sms_path' => '/restapi/v1.0/account/~/extension/~/sms',
        // Access tokens live ~3600s; cache a touch under that and re-mint.
        'token_ttl_seconds' => (int) env('RINGCENTRAL_TOKEN_TTL', 3300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Ads insights provider (ROAS dashboard)
    |--------------------------------------------------------------------------
    |
    | 'null'        => NullAdsProvider: fetches nothing. Default everywhere
    |                  except production — no live Google Ads API calls in dev/test.
    | 'google_ads'  => GoogleAdsProvider: real REST API pulls. Only used when this
    |                  is 'google_ads' AND the app is running in production.
    |
    | API host/version are pinned here; OAuth + developer-token credentials live
    | (encrypted) in the settings table, set via Admin > Integrations.
    |
    */
    'ads_provider' => env('ADS_PROVIDER', 'null'),

    'google_ads' => [
        'api_base' => env('GOOGLE_ADS_API_BASE', 'https://googleads.googleapis.com'),
        'api_version' => env('GOOGLE_ADS_API_VERSION', 'v21'),
        // Number of trailing days the daily pull re-fetches each run, so that
        // late-attributed conversions (which lag in Google Ads) get corrected.
        'lookback_days' => (int) env('GOOGLE_ADS_LOOKBACK_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stripe card Payment Links ("Text card payment link" on the job view)
    |--------------------------------------------------------------------------
    |
    | One-button flow: create a Stripe Payment Link for the job quote plus a card
    | processing surcharge (all folded into ONE line item) and text the customer
    | the hosted pay-by-card URL. Payment Links (not Invoices) so there is no
    | Invoicing fee. The secret key lives encrypted in the settings table
    | (Admin → Integrations → Stripe); live vs test is inferred from its prefix.
    | No card data ever touches this app — the customer pays on Stripe's page.
    |
    | surcharge_percent: % added on top of the quote for paying by card.
    | line_item_label:   the single line item's base description (the vehicle is
    |                    prepended when known; the surcharge note is appended).
    |
    */
    'stripe' => [
        'surcharge_percent' => (float) env('STRIPE_SURCHARGE_PERCENT', 3),
        'line_item_label' => env('STRIPE_LINE_ITEM_LABEL', 'auto glass service'),

        // Stripe's processing fee (2.9% + 30¢ per charge) — used by the bank
        // ledger to ESTIMATE what a card payment nets after Stripe's cut,
        // before a real StripePayout has resolved it. Once resolved, the
        // payout's own balance-transaction fee/net figures are used instead
        // — see App\Models\StripePayoutLine.
        'fee_percent' => (float) env('STRIPE_FEE_PERCENT', 2.9),
        'fee_fixed_cents' => (int) env('STRIPE_FEE_FIXED_CENTS', 30),

        // How many times stripe:resolve-payouts retries a payout whose
        // balance-transaction fetch failed (network/Stripe outage) before
        // giving up and flagging it (StripePayout::STATUS_RESOLUTION_EXHAUSTED)
        // for manual reconciliation through the existing Match-modal flow.
        'payout_resolution_max_attempts' => (int) env('STRIPE_PAYOUT_RESOLUTION_MAX_ATTEMPTS', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default messaging timing (overridable in the settings table)
    |--------------------------------------------------------------------------
    |
    | These are fallback defaults. The Admin > Timing screen writes to the
    | settings table, which takes precedence at runtime.
    |
    */
    'reminders' => [
        // Local (display tz) wall-clock time the night-before reminder is sent.
        'night_before_at' => env('REMINDER_NIGHT_BEFORE_AT', '19:30'),
        // Local wall-clock time the morning-of reminder is sent.
        'morning_of_at' => env('REMINDER_MORNING_OF_AT', '08:00'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Post-payment review drip
    |--------------------------------------------------------------------------
    |
    | followup_local_time: wall-clock time (display tz) the next-day follow-up
    |   (review text 2) goes out, the day after text 1.
    | call_after_days: how long after review text 2 the drip surfaces as "call
    |   needed" on the dashboard (the manual call gate).
    | final_after_days: how long after text 3 (after-call / voicemail text) the
    |   final review ask is sent.
    |
    */
    'reviews' => [
        'followup_local_time' => env('REVIEW_FOLLOWUP_AT', '10:00'),
        'call_after_days' => (int) env('REVIEW_CALL_AFTER_DAYS', 2),
        'final_after_days' => (int) env('REVIEW_FINAL_AFTER_DAYS', 7),
        // Deadline (local wall-clock) on the day a review request opens
        // awaiting_call: past this time an un-logged (or "skip for later")
        // first call auto-falls-through into the regular drip.
        'first_call_deadline_local_time' => env('REVIEW_FIRST_CALL_DEADLINE_AT', '19:00'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tech end-of-day pay summary (opt-in per tech)
    |--------------------------------------------------------------------------
    |
    | Local (display tz) wall-clock time the nightly `tech:eod-summaries`
    | command runs (routes/console.php). The real gate is per-employee
    | (employees.eod_summary_texts_enabled) — this only controls WHEN the
    | sweep runs; it sends nothing for a tech who completed nothing that day.
    |
    */
    'tech_eod_summary_at' => env('TECH_EOD_SUMMARY_AT', '19:00'),

    /*
    |--------------------------------------------------------------------------
    | Receipts (PDF, on-request)
    |--------------------------------------------------------------------------
    |
    | "Send receipt" texts a link to a server-generated PDF invoice/receipt
    | (App\Services\Receipts\ReceiptPdfService) instead of storing a file — the
    | link is a Laravel temporary signed route, valid this many days from the
    | moment it's queued. A re-send just mints a fresh link, so there is no
    | cleanup cron for expired ones.
    |
    */
    'receipts' => [
        'link_ttl_days' => (int) env('RECEIPT_LINK_TTL_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Appointment arrival window
    |--------------------------------------------------------------------------
    |
    | How many hours after the scheduled time the customer-facing arrival window
    | runs to. A job booked at 8am renders as "between 8am–10am" (2h). Overridden
    | per-job when dispatch ticks "Exact time" (then it reads "at 8am" instead).
    |
    */
    'appointment_window_hours' => (int) env('APPOINTMENT_WINDOW_HOURS', 2),

    /*
    |--------------------------------------------------------------------------
    | SendSmsJob retry policy
    |--------------------------------------------------------------------------
    */
    'send' => [
        'max_attempts' => (int) env('SMS_MAX_ATTEMPTS', 3),
        // Backoff seconds per attempt (exponential-ish), used by the job.
        'backoff' => [60, 300, 900],
        // Gap between the Podium handoff note and the Quo dispatch intro, so the
        // new number isn't a surprise (JobLifecycle::sendWelcome).
        'welcome_intro_delay_seconds' => 45,
        // How long AFTER the last still-pending welcome text a dispatcher-pressed
        // follow-up (the scheduling ask) may go out. The welcome pair is delayed,
        // so a follow-up sent the instant it's pressed would overtake it and land
        // out of order — see JobLifecycle::followUpSendAtUtc.
        'follow_up_gap_seconds' => 30,
    ],

    // Delivery-confirmation sweep (alerts:sms-delivery-unconfirmed). OpenPhone/
    // Quo accepts a long UCS-2 text (202) then silently never delivers it AND
    // never sends a message.failed webhook, so the only signal is "sent, but no
    // message.delivered webhook arrived after a grace period".
    'delivery_check' => [
        // Only sweep providers that actually emit delivery-status webhooks —
        // otherwise every send would look "unconfirmed". Only openphone/quo does
        // here (a null per-row provider means the global default, which is quo).
        'providers' => ['openphone'],
        // A normal delivered webhook lands within seconds; wait this long before
        // treating silence as a probable silent drop.
        'grace_minutes' => (int) env('SMS_DELIVERY_GRACE_MINUTES', 15),
        // Ignore anything older than this so a backlog / historical rows (sent
        // before delivery tracking existed) never alert.
        'lookback_hours' => (int) env('SMS_DELIVERY_LOOKBACK_HOURS', 12),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stale lead threshold (hours) for the "Stale pending leads" board filter
    |--------------------------------------------------------------------------
    */
    'stale_pending_hours' => (int) env('STALE_PENDING_HOURS', 48),

    /*
    |--------------------------------------------------------------------------
    | Bank-transaction matching (weekly reconciliation)
    |--------------------------------------------------------------------------
    |
    | Keyword lists the auto-matcher (bank:suggest-matches) uses against Chase
    | transaction descriptions. All matching is case-insensitive substring.
    |
    | parts_vendors: a charge whose description/merchant hits one of these is
    |   treated as a glass-parts purchase and matched to a job by part cost.
    | auto_misc: deterministic descriptions that are auto-matched (no human
    |   confirmation) straight to a misc category — recurring, unambiguous stuff.
    |
    */
    'bank_matching' => [
        // Ceiling on how far back the Match-modal payment-line picker's amount
        // search looks. That branch pulls every unmatched line into PHP to
        // compute a derived amount (expected net / remaining balance) before
        // filtering, unlike the text-search branch's indexed, LIMIT-ed SQL
        // query — so it's the one that needs a floor before the table grows
        // large enough for that full scan to matter. A human actively
        // searching by amount is deliberately given a generous, not a tight,
        // window; this is a performance backstop, not a business rule.
        'search_lookback_days' => 365,
        // ± days between the bank date and the recorded payment / settlement.
        'payment_window_days' => 5,
        'payout_window_days' => 3,
        // Days back a job's part purchase can precede/follow its charge.
        'parts_window_days' => 30,
        // Fuzz for matching a deposit to a recorded payment, and for deciding
        // whether matched transaction(s) reconcile to the collected amount.
        'amount_tolerance_cents' => 500, // ± $5
        // ± days to search a customer's name when the amount doesn't line up.
        'name_window_days' => 14,

        'parts_vendors' => [
            'mygrant', 'pilkington', 'pgw', 'vitro', 'xyg', 'fuyao',
            'auto glass', 'autoglass', 'safelite wholesale', 'igc glass',
            // IGC's real Chase descriptor (see vendors.igc.charge_needles below) —
            // kept here too so BankMatcher::looksLikePartsVendor recognizes an IGC
            // charge and routes it to IgcChargeReconciler instead of the generic
            // single-job findPartPurchase search.
            'igc los angeles', 'import glass',
        ],

        // Outgoing Zelle to one of the owners is a dividend, not a tech payout.
        // key => display name; the matcher classifies an outgoing Zelle whose
        // description contains every name token as a Dividend (auto-matched, no
        // review), and the Finance > Dividends page lists them per owner.
        'dividend_recipients' => [
            'pat_owner' => 'Pat Owner',
            'sam_partner' => 'Sam Partner',
        ],

        'auto_misc' => [
            'google_ads' => ['google ads', 'googleads', 'adwords', 'google *ads'],
            'cc_payment' => ['chase credit crd', 'payment to chase card', 'chase card ending', 'automatic payment - thank'],
            'bank_fee' => ['monthly service fee', 'wire fee', 'overdraft fee'],
            // Outgoing Zelle to Owner Operated = the back-office services fee.
            // Needles are direction-specific ("... to owner operated") so an
            // incoming Zelle from them isn't swallowed as an expense.
            'back_office_services' => ['payment to owner operated', 'zelle to owner operated'],
        ],

        // Per-category ceiling on auto_misc's no-review auto-match — a hit above
        // the cap still gets suggested (as that category) but waits for a human
        // to confirm rather than auto-matching outright. Uncapped categories
        // (absent here) always auto-match regardless of amount.
        'auto_misc_caps' => [
            'google_ads' => 50000, // $500
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Telegram alerts (internal ops group chat)
    |--------------------------------------------------------------------------
    |
    | A Telegram bot posts internal operational alerts into a team group chat.
    | The bot token and destination chat id live encrypted / in the settings
    | table (Admin → Integrations → Telegram); only the API host is pinned here.
    | Post a digest by hand with `php artisan alerts:telegram-digest --when=midday`.
    |
    */
    'telegram' => [
        'api_base' => env('TELEGRAM_API_BASE', 'https://api.telegram.org'),
        // The three daily ops digests (alerts:telegram-digest). Everything that
        // is "here is what still needs a human" — needs scheduling, parts to
        // order, prepay these parts, completed but unpaid — posts on this fixed
        // rhythm instead of on five overlapping schedules. See OpsDigest.
        'digest_morning_at' => env('TELEGRAM_DIGEST_MORNING_AT', '07:00'),
        'digest_midday_at' => env('TELEGRAM_DIGEST_MIDDAY_AT', '12:00'),
        'digest_evening_at' => env('TELEGRAM_DIGEST_EVENING_AT', '17:00'),
        // Local (display tz) wall-clock time the weekly Owed-from-Xpro digest
        // posts, every Friday — everything currently outstanding.
        'xpro_alert_at' => env('TELEGRAM_XPRO_ALERT_AT', '08:00'),
        // ...and the weekly OVERDUE chase list, every Wednesday. Noon is the
        // collection deadline itself (Job::xproCollectionDueAt), so the digest
        // posts exactly as last week's money goes late.
        'xpro_overdue_alert_at' => env('TELEGRAM_XPRO_OVERDUE_ALERT_AT', '12:00'),
        // ONE quiet-hours rule for every RECURRING alert (App\Support\AlertWindow).
        // Each of those runs on a bare interval — every 5 / 15 / 30 minutes, or
        // hourly — which knows nothing about the time of day, so without this
        // gate a nag posts all night. Outside these local wall-clock hours the
        // sweep is a silent no-op and says the same thing again after 7am.
        'alert_window_start' => env('TELEGRAM_ALERT_WINDOW_START', '07:00'),
        'alert_window_end' => env('TELEGRAM_ALERT_WINDOW_END', '19:00'),
        // Days we post NOTHING to the group chat, whatever the hour. We are
        // closed Sunday (App\Support\ClosedDays), so an alert nobody can act on
        // is only noise. ⚠️ Unlike the hours above this also silences the
        // ONE-SHOT alerts (a booking, a failed send) — they are dropped for the
        // day, not deferred. Owner's call, 2026-08-03. Set to an empty string
        // (TELEGRAM_SILENT_DAYS=) to alert every day again.
        'silent_days' => env('TELEGRAM_SILENT_DAYS', 'sunday'),
        // Overdue-completion alert (jobs + warranty visits): how many hours past
        // the arrival window it may sit un-completed before we ping the group
        // (8am–10am window → alert at 11am with the default 1h), and how often
        // the ping REPEATS while it stays open. Swept every 15 min.
        'overdue_grace_hours' => (int) env('TELEGRAM_OVERDUE_GRACE_HOURS', 1),
        'overdue_repeat_hours' => (int) env('TELEGRAM_OVERDUE_REPEAT_HOURS', 1),
        // Unscheduled-warranty nag: 7am + 3pm until the return visit is booked.
        // ⚠️ PAUSED 2026-07-30 (owner's call) — the command and these times are
        // kept, but routes/console.php no longer schedules it.
        'warranty_unscheduled_morning_alert_at' => env('TELEGRAM_WARRANTY_UNSCHEDULED_MORNING_ALERT_AT', '07:00'),
        'warranty_unscheduled_afternoon_alert_at' => env('TELEGRAM_WARRANTY_UNSCHEDULED_AFTERNOON_ALERT_AT', '15:00'),
        // Mygrant Monday-morning "upload the order history" nag.
        'mygrant_upload_nag_at' => env('TELEGRAM_MYGRANT_UPLOAD_NAG_AT', '08:00'),
        // IGC charge result — posts right after igc:reconcile.
        'igc_charge_result_at' => env('TELEGRAM_IGC_CHARGE_RESULT_AT', '06:35'),
        // IGC aging look-at digest — invoices with a non-zero balance older
        // than vendors.igc.aging_alert_days.
        'igc_aging_at' => env('TELEGRAM_IGC_AGING_AT', '08:00'),
        // IGC return-rate digest — MONTHLY (owner decision, see
        // App\Services\Telegram\IgcReturnRateAlert's doc comment), posted on
        // the 1st of the month.
        'igc_return_rate_at' => env('TELEGRAM_IGC_RETURN_RATE_AT', '08:00'),
        // IGC Monday-morning "upload the two exports" nag.
        'igc_upload_nag_at' => env('TELEGRAM_IGC_UPLOAD_NAG_AT', '08:00'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Parts ordering — supplier search-link templates
    |--------------------------------------------------------------------------
    |
    | Used by App\Services\Parts\PartsOrderLinks to build a direct "search this
    | part" URL per supplier. %s placeholders are filled with rawurlencode()'d
    | values via sprintf.
    |
    */
    'parts_ordering' => [
        'mygrant_url' => 'https://mygrantglass.com/pages/search.aspx?q=%s&sc=%s&do=Search',
        'igc_url' => 'https://importglasscorp.com/glass/%s',
    ],

    /*
    |--------------------------------------------------------------------------
    | Vendor parts-invoice reconciliation
    |--------------------------------------------------------------------------
    |
    | Per-supplier settings for App\Services\Vendors\* (order-history / credit
    | memo ingest, billing-cycle math, and the charge reconciler built on top).
    |
    */
    'vendors' => [
        // The day Job records begin. A vendor line dated before this can never
        // find a job to match — the job simply was not tracked here yet — so
        // VendorLineMatcher files it as no_job/'pre_job_tracking' instead of
        // leaving it in "Lines to match" forever looking like a broken match.
        // Deliberately NOT nested under a supplier: this is a fact about our
        // own data, identical for Mygrant and IGC. Null disables the check.
        // Same boundary IGC Layer 4 already uses, for the same reason.
        'job_tracking_start' => env('VENDOR_JOB_TRACKING_START', '2026-06-26'),

        'mygrant' => [
            // Our account numbers on the Mygrant order-history export — not
            // currently enforced by the importer, kept here for reference /
            // a future sanity check that an uploaded file is really ours.
            'account' => ['bill_to' => 'C000001-000', 'ship_to' => 'C000001-001'],

            // Mygrant bills WEEKLY: everything confirmed (picked up) in a
            // window gets swept into one invoice cut on the next Sunday —
            // EXCEPT a window can't cross a calendar month, so a period is
            // shortened to end on the last day of the month instead. Verified
            // against 228 real invoice groups with zero exceptions.
            'cut_weekday' => 0, // Sunday (Carbon's day-of-week numbering: 0=Sun)

            // The invoice is charged to our card the first Wednesday on/after
            // (cut_date + settle_lag_days) — i.e. a few business days after
            // the week closes, giving Mygrant time to actually cut the invoice.
            'settle_weekday' => 3, // Wednesday
            'settle_lag_days' => 3,

            // Chase descriptor to recognize a Mygrant charge on the bank
            // ledger (e.g. "MYGRANT GLASS CO #018") — used by the (separate)
            // charge reconciler, not by anything in this file.
            'charge_needles' => ['mygrant glass'],
            'card_mask' => '7532',

            // A part with no parseable vehicle AND under this amount reads as
            // shop supplies (wiper blades, urethane, primer, moulding, kits)
            // rather than a job's glass — informational threshold for the
            // reconciler/matcher, not enforced here.
            'consumable_max_cents' => 10000, // $100

            // The owner wants ANY difference between what we were charged and
            // what the invoice says flagged — no fuzz allowed, unlike bank
            // reconciliation's ± $5 tolerance.
            'variance_tolerance_cents' => 0,

            // ± days a job-matching candidate may be searched around a line's
            // order/confirmed date.
            'match_window_days' => 7,

            // Days since the last successful order-history upload before the
            // Monday-morning Telegram nag fires (App\Services\Telegram\MygrantUploadNagAlert).
            'upload_nag_days' => 14,

            // App\Services\Vendors\VendorChargeReconciler: when a Chase charge
            // doesn't sum exactly against its own billing-period documents, it
            // searches for a unique exact-sum SUBSET within that period (or,
            // when the period is empty, within the narrow backlog-straggler
            // fallback). Capped so the search — a bitmask over the candidate
            // set — stays instant; above this count it's skipped entirely.
            'subset_search_cap' => 10,

            // Explicit override for VendorChargeReconciler::coverageStartDate()
            // — a 'Y-m-d' string. A document settling before this date can
            // never be charged (it predates the bank ledger's own Mygrant
            // coverage, e.g. Plaid was only connected in April even though
            // invoices go back to January) and is excluded from every
            // candidate set. Left null so it's derived automatically from the
            // earliest Mygrant charge already on the ledger; set this only if
            // that auto-detection ever needs a manual override.
            'bank_coverage_start' => null,
        ],

        // App\Services\Vendors\Igc\* — IGC has NO billing cycle (one order = one
        // invoice, issued immediately; see the vendor_documents migration note),
        // so there is no cut/settle-weekday config here the way Mygrant has one.
        'igc' => [
            // Chase descriptor for an IGC charge (card mask 7532). §0 note: the
            // rules doc's "igc glass" needle never actually appears on the real
            // Chase export — "IGC LOS ANGELES" / "IMPORT GLASS" do. Also mirrored
            // into bank_matching.parts_vendors above so BankMatcher recognizes an
            // IGC charge as a vendor charge at all before routing it here.
            'charge_needles' => ['igc los angeles', 'import glass'],
            'card_mask' => '7532',

            // App\Services\Vendors\Igc\IgcChargeReconciler: how far a charge's
            // post date may lag/lead the payment date recorded in IGC's own
            // report before widening the exact-date search (Rule 2).
            'match_window_days' => 3,
            'charge_date_window_days' => 3,

            // No fuzz allowed on a charge<->payment-group match — §0/C1's
            // whole point is exact-date grouping ties out to the penny; a
            // near-miss is a real gap (missing memo / stale report), not noise.
            'variance_tolerance_cents' => 0,

            // Layer 5 aging (App\Services\Telegram\IgcAgingAlert): a non-zero
            // invoice balance older than this many days is worth a look. The
            // rules doc's 14 days fires on 29% of real invoices (mostly healthy
            // ones IGC just hasn't drawn on yet — observed payment gaps of 4 to
            // 59 days, §1.3); 30 days fires on 13% and stays meaningful. See the
            // build spec §7 for the measured distribution.
            'aging_alert_days' => 30,

            // App\Services\Telegram\IgcReturnRateAlert: monthly (not weekly —
            // owner decision) credit-dollar-vs-invoice-dollar percentage that's
            // worth flagging as a trend to watch.
            'return_rate_alert_pct' => 30,

            // Days since the last successful UPLOAD OF EITHER export (order
            // history or invoice history — they're one deliverable, see
            // IgcUploadNagAlert) before the Monday-morning Telegram nag fires.
            'upload_nag_days' => 14,

            // IGC part-code prefixes that read as shop supplies (urethane, kits,
            // moulding) rather than glass — App\Services\Vendors\Igc\IgcLineMatcher
            // (deferred) will flag these to no_job/shop_supplies without ever
            // reaching a job. Kept here now so the second pass doesn't need a
            // config migration of its own.
            'consumable_prefixes' => ['WFS', 'WKT', 'USM'],

            // App\Services\Vendors\Igc\IgcLineMatcher (deferred): jobs dated
            // before this cutoff have no chance of a real job match — the
            // earliest IGC-warehouse job on file is 2026-06-26 while the invoice
            // history reaches back to 2025-10-08. See the build spec §6 "Owner
            // decision 1" for the full reasoning.
            'job_matching_cutoff' => '2026-06-26',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI scheduling ("Schedule w/ AI")
    |--------------------------------------------------------------------------
    |
    | Texts technicians one at a time (priority order) proposing a specific
    | slot for a job, reads their SMS reply (English or Spanish) via Claude
    | Haiku, negotiates within the customer's constraint, and auto-schedules
    | on acceptance. See docs/ai-scheduling-spec.md for the full design.
    |
    | The global on/off toggle and the Anthropic API key live encrypted in the
    | settings table (Admin -> Integrations -> AI assist), same pattern as the
    | bank-matching AI assist. This config only holds tuning knobs, no secrets.
    |
    */
    'ai_scheduling' => [
        // Local (display tz) wall-clock boundaries of each part of day, used
        // both to classify a customer's time preference into a window and to
        // bucket a tech's existing jobs when scoring how busy a day looks.
        'day_parts' => [
            'morning' => ['08:00', '12:00'],
            'afternoon' => ['12:00', '17:00'],
            'evening' => ['17:00', '19:00'],
        ],
        // Jobs a tech already has in one part of day before that part reads
        // as "busy" rather than merely "light" — see TechDayLoad::bucket. One
        // job in the morning is still fine to stack another on; two is busy.
        'busy_threshold' => (int) env('AI_SCHEDULING_BUSY_THRESHOLD', 2),
        // How long a contacted tech has to reply before the sweep moves on to
        // the next one in priority order. 90 minutes (owner's call, 2026-07-27;
        // was 2 hours): techs are on a windshield, not watching their phone, so
        // burning through the whole priority list in an hour just to escalate is
        // worse than waiting - but two hours per tech is slower than the job can
        // afford. Same-day work keeps its much shorter fuse below.
        'response_window_minutes' => (int) env('AI_SCHEDULING_RESPONSE_WINDOW_MINUTES', 90),
        'same_day_response_window_minutes' => (int) env('AI_SCHEDULING_SAME_DAY_RESPONSE_WINDOW_MINUTES', 10),
        // After a tech agrees to a whole part of day we come back with the
        // concrete 2-hour slot we mean to book ("we will book it for 8am-10am,
        // confirming that works") and wait this long for them to name a
        // different time. Silence books what we proposed - they already said
        // yes to the day, so this is a courtesy, not a second gate. Capped by
        // the response window above, so same-day work doesn't stall on it.
        'slot_confirm_window_minutes' => (int) env('AI_SCHEDULING_SLOT_CONFIRM_WINDOW_MINUTES', 20),
        // Once the job is booked we ask the tech which warehouse to order the
        // part from and hold the attempt open this long for their answer. Kept
        // shortish because the attempt stays "live" while we wait, which keeps
        // that tech off other sessions' offer lists. No answer is fine - the
        // job just keeps no parts source and whoever orders the part picks one.
        'warehouse_ask_window_minutes' => (int) env('AI_SCHEDULING_WAREHOUSE_ASK_WINDOW_MINUTES', 30),
        // Cap on how many techs one session will work through before giving
        // up and handing the job back to a human.
        'max_techs_per_session' => (int) env('AI_SCHEDULING_MAX_TECHS', 6),
        // A tech gets pushed back on a counter-offer that doesn't fit the
        // customer's constraint this many times before the session gives up
        // on them and moves to the next tech.
        'max_reiterations' => (int) env('AI_SCHEDULING_MAX_REITERATIONS', 1),
        // How many times a customer can redirect a live session to a new
        // day/time before it escalates to a human instead of restarting again.
        'max_retargets' => (int) env('AI_SCHEDULING_MAX_RETARGETS', 2),
        // Below this confidence, a classified reply is treated as unclear and
        // escalated to a human rather than acted on.
        'min_confidence' => (float) env('AI_SCHEDULING_MIN_CONFIDENCE', 0.6),
        // Local (display tz) window in which the CLOCK may act (owner's call,
        // 2026-07-27). Outside 6:00am-10:30pm the sweep holds: it does not
        // cold-text the next tech, time a silent one out, or book a slot on
        // silence. A tech who texts US outside the window is a conversation
        // already in progress and is answered at any hour - see
        // AiSchedulingService::outreachAllowedNow().
        'active_hours' => [
            'from' => env('AI_SCHEDULING_ACTIVE_FROM', '06:00'),
            'until' => env('AI_SCHEDULING_ACTIVE_UNTIL', '22:30'),
        ],
        'model' => env('AI_SCHEDULING_MODEL', 'claude-haiku-4-5-20251001'),
        // How close (minutes) a tech's proposed clock time must land to an
        // EXACT customer request to count as satisfying it outright.
        'exact_tolerance_minutes' => (int) env('AI_SCHEDULING_EXACT_TOLERANCE_MINUTES', 0),
    ],
];
