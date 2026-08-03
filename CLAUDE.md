# CLAUDE.md — Rules for working in this repository

This is a field-service operations dashboard (Laravel 12 + Filament 4) being set up for a new business.
**If setup is not finished** (check `SETUP_STATUS.md`), read `SETUP_PROMPT.md` and follow it — it is the
master script. Once setup is complete, this file should be rewritten to describe the finished system;
that rewrite is part of the setup.

## How to talk to the owner
- The owner is **not technical**. Use plain, simple words. Short sentences.
- One question or one step at a time. Wait for the answer.
- Confirm before anything hard to undo or anything that costs money.

## Engineering rules (hard-won — do not relearn these the painful way)
- **Timezone:** store UTC (`APP_TIMEZONE=UTC`), display/schedule via `config('glass.timezone')`. Never
  set the app timezone to local time — every reminder shifts by an hour.
- **Queue, cache, sessions:** `database` driver. Texts only send if a queue worker and the every-minute
  scheduler cron are running.
- **Text messages render at QUEUE time**, not send time. Editing a template does not fix messages already
  queued; language switches re-render via `OutboxService::rerenderUnsentForCustomer`.
- **Template bodies: ASCII punctuation only** — no curly quotes, no em/en-dashes. One smart character
  forces the whole SMS into UCS-2 encoding and long texts get silently dropped by carriers. The template
  editor validates this; keep it that way.
- **Merge variables** must exist in `TemplateRenderer::SUPPORTED_VARS`. Unknown tokens render as blanks
  in real customer texts.
- **Real providers only send when `APP_ENV=production`** and outgoing texts are not paused; otherwise
  everything goes to the log provider. Use that for safe testing.
- **Never hardcode business data** (names, phones, prices) in code. Entities, settings (encrypted for
  secrets), and message templates are the homes for it.
- **No secrets in the repo.** Credentials live in the server `.env` or encrypted DB settings.
- **Our own Blade views: inline styles + `x-filament::*` components only.** There is no custom Filament
  theme, so Tailwind classes in our views are never compiled and render unstyled.
- **Tests:** `php artisan test` (SQLite in-memory, no setup). Run them after every change; commit only green.
- **Idempotency:** automated texts use idempotency keys (see `OutboxService`) so retries and double-clicks
  never double-text a customer. Follow the existing patterns when adding sends.

## Workflow
- Single branch. Commit small, working steps with clear messages. Push regularly so nothing is lost.
- Update `SETUP_STATUS.md` and `BUSINESS_PROFILE.md` before ending any working session.
