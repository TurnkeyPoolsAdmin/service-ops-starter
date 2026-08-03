<?php

use Illuminate\Support\Facades\Schedule;

// Outbox processor — dispatches due SMS every minute (see Cloudways cron in README).
Schedule::command('messages:process-due')
    ->everyMinute()
    ->withoutOverlapping();

// Drain the database queue every minute via the scheduler. The Cloudways
// Supervisor worker is pinned to the (now-disabled) Redis connection and can't
// consume our database queue; until that root-owned config is corrected in the
// Cloudways panel, this short-lived worker actually delivers the SMS. umask 002
// keeps any files it touches group-writable so php-fpm (a different OS user)
// can still write them. --stop-when-empty exits as soon as the queue is clear.
Schedule::exec('umask 002 && '.PHP_BINARY.' '.base_path('artisan').' queue:work database --stop-when-empty --max-time=55 --tries=3 --no-interaction')
    ->everyMinute()
    ->withoutOverlapping();

// Review drips — sends due review texts (next-day follow-up, week-later final)
// every 5 minutes. Idempotent per job+template; the manual steps (the call,
// "review left") live on the Reviews dashboard.
Schedule::command('reviews:advance')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// SMS delivery watchdog — every 5 min, alert on texts OpenPhone/Quo accepted
// but never confirmed delivered (no message.delivered/message.failed webhook
// after the grace period). This is the ONLY signal for a silently-dropped long
// UCS-2 text; the provider never sends a failure event for it. Stamps each row
// so it alerts once (App\Services\Telegram\MessageDeliveryUnconfirmedAlert).
// Silent outside App\Support\AlertWindow (7am-7pm PT) — the stamp is only
// written on a successful send, so an evening drop is named at 7am.
Schedule::command('alerts:sms-delivery-unconfirmed')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Connector health sweep — hourly probe of every configured external
// credential (Podium OAuth, Quo key, Google Ads refresh token, Plaid item,
// Stripe key, RingCentral JWT). Alerts the group chat only when one BREAKS or
// RECOVERS, never while it stays broken. Exists because Podium's refresh token
// died silently for 8 days in July 2026 and only surfaced as failed texts.
// The probe always runs and always records state; only the POST waits for
// App\Support\AlertWindow (7am-7pm PT), so a 2am break is announced at 7am.
Schedule::command('connectors:health-check')
    ->hourly()
    ->withoutOverlapping();

// Tech reminder digests — runs every 15 min and self-gates on the configured
// night-before / morning-of times (idempotent per tech/phase/day). Queues each
// tech a digest of their jobs; the every-minute processor then delivers it.
Schedule::command('reminders:tech-digests')
    ->everyFifteenMinutes()
    ->timezone(config('glass.timezone', 'America/Los_Angeles'))
    ->withoutOverlapping();

// Google Ads ingest — refresh the trailing lookback window once daily so the
// ROAS dashboard has yesterday's spend + late-attributed conversions. No-op
// outside production (NullAdsProvider). 6:00 AM in the display timezone.
Schedule::command('ads:pull-metrics')
    ->dailyAt('06:00')
    ->timezone(config('glass.timezone', 'America/Los_Angeles'))
    ->withoutOverlapping();

// Plaid bank ingest — pull the latest Chase checking + credit-card transactions
// once daily via incremental /transactions/sync. No-op until a bank is linked
// (Connect Chase in Admin > Integrations). 6:15 AM in the display timezone.
Schedule::command('bank:pull-transactions')
    ->dailyAt('06:15')
    ->timezone(config('glass.timezone', 'America/Los_Angeles'))
    ->withoutOverlapping();

// Bank auto-matcher — right after the pull, propose what each new transaction
// was (customer payment / parts / tech payout / misc) so the weekly
// reconciliation pass is mostly one-click confirms.
Schedule::command('bank:suggest-matches')
    ->dailyAt('06:25')
    ->timezone(config('glass.timezone', 'America/Los_Angeles'))
    ->withoutOverlapping();

// Stripe payout <-> Chase deposit linking — right after bank:suggest-matches,
// same reasoning as mygrant:reconcile below: a same-morning payout shouldn't
// wait until tomorrow. Only stamps stripe_payouts.bank_transaction_id (never
// touches bank_transactions.match_status) — see StripePayoutReconciler.
Schedule::command('stripe:match-payouts')
    ->dailyAt('06:26')
    ->timezone(config('glass.timezone', 'America/Los_Angeles'))
    ->withoutOverlapping();

// Retry any Stripe payout whose balance-transaction fetch previously failed
// (Stripe outage / network blip at webhook time) — owns the retry budget the
// payout.paid webhook itself doesn't get (Stripe's own redelivery window is
// limited and uncontrolled). Escalates to Telegram + the Issues queue once
// glass.stripe.payout_resolution_max_attempts is exhausted.
Schedule::command('stripe:resolve-payouts')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Mygrant parts-invoice reconciliation — charges <-> documents, then billed
// lines <-> jobs. Runs right after bank:suggest-matches (which already routes
// a Mygrant charge through the same reconciler as it processes the ledger) so
// a same-morning upload doesn't wait until tomorrow to re-attempt a charge
// that's been sitting short/unmatched. Idempotent; safe to run often.
Schedule::command('mygrant:reconcile')
    ->dailyAt('06:30')
    ->timezone(config('glass.timezone', 'America/Los_Angeles'))
    ->withoutOverlapping();

// Mygrant Monday-morning nag — reminds the team to upload the last 14 days of
// order history if nothing has come in recently, so the reconciler always has
// fresh data to work against. No-op when a recent upload already exists /
// bot unconfigured / alerts disabled.
Schedule::command('alerts:telegram-mygrant-upload-nag')
    ->weeklyOn(1, config('glass.telegram.mygrant_upload_nag_at', '08:00'))
    ->timezone(config('glass.timezone', 'America/Los_Angeles'))
    ->withoutOverlapping();

// Mygrant charge result — posts what the last card charge resolved to (ties
// out / short by $X with the exact expected credit memo amount / escalate
// when higher), right after the reconcile pass. No-op when there's nothing
// new to report (already posted, or nothing charged yet).
Schedule::command('alerts:telegram-mygrant-charge-result')
    ->dailyAt('06:35')
    ->timezone(config('glass.timezone', 'America/Los_Angeles'))
    ->withoutOverlapping();

// IGC parts-invoice reconciliation — net (pair returns to originals), then
// charges <-> payment groups. Same slot as mygrant:reconcile (06:30) — both
// commands are independent and safe to run in the same minute. Runs right
// after bank:suggest-matches so a fresh invoice-history upload doesn't wait
// until tomorrow to re-attempt a charge that's been sitting unresolved.
Schedule::command('igc:reconcile')
    ->dailyAt('06:30')
    ->timezone(config('glass.timezone', 'America/Los_Angeles'))
    ->withoutOverlapping();

// IGC charge result — posts I1 (no payment record for the charge's date) or
// I2 (charge doesn't add up to its exact-date payment group), right after
// the reconcile pass. No-op when there's nothing new to report.
Schedule::command('alerts:telegram-igc-charge-result')
    ->dailyAt(config('glass.telegram.igc_charge_result_at', '06:35'))
    ->timezone(config('glass.timezone', 'America/Los_Angeles'))
    ->withoutOverlapping();

// IGC aging look-at digest (I4) — invoices with a non-zero balance open more
// than 30 days. No-op when nothing is aging / bot unconfigured / disabled.
Schedule::command('alerts:telegram-igc-aging')
    ->dailyAt(config('glass.telegram.igc_aging_at', '08:00'))
    ->timezone(config('glass.timezone', 'America/Los_Angeles'))
    ->withoutOverlapping();

// IGC monthly return-rate digest (I3) — owner decision: monthly, not weekly
// (see App\Services\Telegram\IgcReturnRateAlert's doc comment). Posted the
// 1st of each month, reporting on the month that just ended. No-op when
// last month's rate was under the configured threshold.
Schedule::command('alerts:telegram-igc-return-rate')
    ->monthlyOn(1, config('glass.telegram.igc_return_rate_at', '08:00'))
    ->timezone(config('glass.timezone', 'America/Los_Angeles'))
    ->withoutOverlapping();

// IGC Monday-morning nag — reminds the team to upload a fresh invoice-history
// export if nothing has come in recently, so reconciliation and netting
// always have current data. No-op when a recent upload already exists / bot
// unconfigured / alerts disabled.
Schedule::command('alerts:telegram-igc-upload-nag')
    ->weeklyOn(1, config('glass.telegram.igc_upload_nag_at', '08:00'))
    ->timezone(config('glass.timezone', 'America/Los_Angeles'))
    ->withoutOverlapping();

// Telegram ops digest — 7am, 12pm and 5pm PT. ONE post per slot carrying every
// "still needs a human" queue: needs scheduling, parts ready to order, prepay
// these parts (today + tomorrow), completed but unpaid. The 7am run posts the
// URGENT needs-scheduling-today list as a second, standalone message ahead of
// the digest; midday and evening merge those jobs into the digest instead.
// Replaced alerts:telegram-daily + -prepay-parts + -unpaid-jobs (five posts on
// overlapping schedules) on 2026-07-30 — see App\Services\Telegram\OpsDigest.
// No-op when nothing is pending / the bot is unconfigured / alerts are disabled.
Schedule::command('alerts:telegram-digest --when=morning')
    ->dailyAt(config('glass.telegram.digest_morning_at', '07:00'))
    ->timezone(config('glass.timezone', 'America/Los_Angeles'))
    ->withoutOverlapping();

Schedule::command('alerts:telegram-digest --when=midday')
    ->dailyAt(config('glass.telegram.digest_midday_at', '12:00'))
    ->timezone(config('glass.timezone', 'America/Los_Angeles'))
    ->withoutOverlapping();

Schedule::command('alerts:telegram-digest --when=evening')
    ->dailyAt(config('glass.telegram.digest_evening_at', '17:00'))
    ->timezone(config('glass.timezone', 'America/Los_Angeles'))
    ->withoutOverlapping();

// Telegram weekly Owed-from-Xpro digest — posts every job the Xpro team collected
// payment on that we still need to collect back, to the team group chat every
// Friday. No-op when nothing is outstanding / the bot is unconfigured / disabled.
Schedule::command('alerts:telegram-xpro-weekly')
    ->weeklyOn(5, config('glass.telegram.xpro_alert_at', '08:00'))
    ->timezone(config('glass.timezone', 'America/Los_Angeles'))
    ->withoutOverlapping();

// Telegram weekly Owed-from-Xpro OVERDUE chase list — the Friday digest's twin,
// posted every WEDNESDAY at noon: only what is still uncollected from last week,
// i.e. past its deadline (noon Wednesday is that deadline instant, so the digest
// lands exactly as the money goes late). Reposts the whole list weekly until the
// money is back; the same jobs also sit on the Issues queue via issues:sweep.
// No-op when nothing is overdue / the bot is unconfigured / alerts are disabled.
Schedule::command('alerts:telegram-xpro-overdue')
    ->weeklyOn(3, config('glass.telegram.xpro_overdue_alert_at', '12:00'))
    ->timezone(config('glass.timezone', 'America/Los_Angeles'))
    ->withoutOverlapping();

// Overdue-completion alert — pings the group when a scheduled job is still not
// marked work-complete a grace period (default 1h) past its arrival window
// (8am–10am window → 11am), then REPEATS hourly until it's closed. Swept every
// 15 min so the first ping lands promptly; the alert itself enforces the hourly
// repeat and goes silent outside App\Support\AlertWindow (7am-7pm PT). No-op
// when nothing is overdue / the bot is unconfigured / alerts are disabled.
Schedule::command('alerts:telegram-overdue-jobs')
    ->everyFifteenMinutes()
    ->timezone(config('glass.timezone', 'America/Los_Angeles'))
    ->withoutOverlapping();

// Time-relative Issues sweep — materializes "completed but still unpaid the next
// day", "job scheduled for today with its part not ordered", and "Xpro money
// overdue to collect back" onto the super-admin Issues queue, and heals them once
// paid / ordered / collected / the day rolls past. Recompute-based (idempotent),
// no external calls — runs hourly.
Schedule::command('issues:sweep')
    ->hourly()
    ->timezone(config('glass.timezone', 'America/Los_Angeles'))
    ->withoutOverlapping();

// ⚠️ PAUSED 2026-07-30 (owner's call) — the unscheduled-warranty nag (every filed
// claim still waiting for a return visit to be booked, 7am + 3pm) is NOT
// scheduled. The command, its builder, its config times and its tests are all
// intact; uncomment these two entries to switch it back on. Open claims still
// surface on the Warranty dashboard nav badge in the meantime.
//
// Schedule::command('alerts:telegram-warranty-unscheduled')
//     ->dailyAt(config('glass.telegram.warranty_unscheduled_morning_alert_at', '07:00'))
//     ->timezone(config('glass.timezone', 'America/Los_Angeles'))
//     ->withoutOverlapping();
//
// Schedule::command('alerts:telegram-warranty-unscheduled')
//     ->dailyAt(config('glass.telegram.warranty_unscheduled_afternoon_alert_at', '15:00'))
//     ->timezone(config('glass.timezone', 'America/Los_Angeles'))
//     ->withoutOverlapping();

// Overdue-warranty-completion alert — the warranty twin of the job alert above,
// and it behaves identically: first ping 1h past the visit's window, then hourly
// until resolved, silent outside App\Support\AlertWindow (7am-7pm PT). Swept
// every 15 min. No-op when nothing is overdue / unconfigured / disabled.
Schedule::command('alerts:telegram-warranty-overdue')
    ->everyFifteenMinutes()
    ->timezone(config('glass.timezone', 'America/Los_Angeles'))
    ->withoutOverlapping();

// Same-day parts-unordered nag — same-day scheduling no longer blocks on
// ordering the part (schedule modal shows a non-blocking warning instead), so
// this repeats every 30 min until the part is marked ordered. Reposts the live
// list each run (no per-row stamp) — a job drops off the moment it's ordered.
// ⚠️ Silent outside App\Support\AlertWindow (7am-7pm PT). Without that gate it
// posted all night: at 00:00 PT the day rolls over and every unordered part for
// the coming day counts as "TODAY", so the group got a 🚨 every 30 minutes from
// midnight until someone woke up. No-op when nothing is outstanding / the bot
// is unconfigured / disabled.
Schedule::command('alerts:telegram-same-day-parts-unordered')
    ->everyThirtyMinutes()
    ->timezone(config('glass.timezone', 'America/Los_Angeles'))
    ->withoutOverlapping();

// Review call-first nag — hourly, reposts the live list of jobs still awaiting
// a first review call. No-op when nothing is outstanding, outside
// App\Support\AlertWindow (7am-7pm PT), or the bot is unconfigured / disabled.
Schedule::command('alerts:telegram-review-calls')
    ->hourly()
    ->timezone(config('glass.timezone', 'America/Los_Angeles'))
    ->withoutOverlapping();

// Tech end-of-day pay summary — opt-in per tech (employees.eod_summary_texts_enabled).
// Sends one recap text to each opted-in tech who completed a job (or a paid
// warranty visit) that local day; a no-op for a tech with nothing completed.
Schedule::command('tech:eod-summaries')
    ->dailyAt(config('glass.tech_eod_summary_at', '19:00'))
    ->timezone(config('glass.timezone', 'America/Los_Angeles'))
    ->withoutOverlapping();

// AI scheduling — move live sessions forward on the clock. Technician replies
// arrive push-style from the inbound SMS webhook, so this only handles what
// TIME changes: a tech who never answered inside their response window (90
// min, 10 for a same-day job), and a session whose requested day has passed.
// Every two minutes because the response window is measured in minutes — a
// slower tick would leave a job sitting on a tech who already went silent.
// Between 10:30pm and 6am Pacific it holds every session where it stands
// (AiSchedulingService::outreachAllowedNow) rather than texting anyone; the
// cron itself still runs, since a held session needs picking up at 6:00am.
// A no-op (one query) when no session is running.
Schedule::command('ai:advance-sessions')
    ->everyTwoMinutes()
    ->timezone(config('glass.timezone', 'America/Los_Angeles'))
    ->withoutOverlapping();
