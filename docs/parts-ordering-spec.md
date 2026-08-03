# Spec: Parts Ordering — Rev 2 update (three-flow model)

**Status:** Rev 1 is SHIPPED (commit `4c1bd84`, live). This document specs only the **changes** to
take the implemented feature to the owner's revised three-flow model. Do not rebuild what exists.

> **Rev 3 (2026-07-23, shipped) superseded the "Change 2" blocking confirm below.** Same-day
> scheduling no longer requires the "I've ordered the part" checkbox to submit — it's now a
> non-blocking warning, and a Telegram alert (`alerts:telegram-same-day-parts-unordered`, every 30
> min) nags the group chat until the part is marked ordered. See `CLAUDE.md` → "Same-day ordering is
> a WARNING, not a block". The rest of this document (warehouse-required, the prompt-after-scheduling
> flow) is unchanged.

## What's already live (rev 1 — do not touch unless listed below)

- `warehouses.supplier` (`mygrant`/`igc`/null) + `supplier_code`, `PartsSupplier` enum, warehouse
  form/table fields, name-based backfill (`2026_07_09_140000`).
- `App\Services\Parts\PartsOrderLinks` — Mygrant/IGC/no-link URL builder (`config('glass.parts_ordering')`),
  with a warehouse override param for live previews.
- `App\Support\BusinessDays` — `nextBusinessDay()` (Mon–Sat, skips Sundays + `Holiday::isHoliday`)
  and `isWithinNextBusinessDay()`.
- `jobs.parts_ordered_at/by` (`2026_07_09_150000`), `Job::isPartsOrdered()`,
  `scopePartsToOrder` / `scopePartsReadyToOrder`, `JobLifecycle::markPartsOrdered()`
  (idempotent, activity-logged, `app/Services/JobLifecycle.php:790`).
- `JobActions::orderParts()` (`app/Filament/Resources/Jobs/Support/JobActions.php:666`) — shared
  modal: warehouse picker for jobs missing one, live link preview (`orderLinksHtml`), submit =
  "Mark ordered".
- `JobActions::notifyScheduled()` / `sendOrderPartsPrompt()` (`JobActions.php:107-165`) — after
  schedule/reschedule, jobs due **within the next business day** get a persistent warning
  notification with order-link buttons; further-out jobs get a plain success notification.
- **Operations → Parts to Order** page (`app/Filament/Pages/PartsToOrder.php`) — ready/ahead/all
  tabs, warning nav badge, row actions Order part + Mark ordered.
- `tests/Feature/PartsOrderingTest.php` (BusinessDays, links, scopes, markPartsOrdered).

## The revised model (owner, 2026-07-09)

| Scheduled for | At schedule time | Order prompt |
|---|---|---|
| **Same day** (today PT) | Tech + **warehouse now required** | **Blocking**: links + "I've ordered the part" confirm INSIDE the schedule modal; can't submit (so no texts/reminders/calendar) until confirmed. Submitting stamps the job ordered. |
| **Next business day** | Tech + warehouse required | Schedule submits normally (texts etc. fire), then dispatch is immediately prompted through the order flow. |
| **More than 1 business day** | Tech + warehouse required | Same prompt as next-day; job also sits on the Parts to Order dashboard ("Scheduled ahead" → auto-"Ready to order" as the date nears — already works, date-derived). |

## Change 1 — warehouse required on schedule/reschedule

`JobActions::scheduleFields()` (`JobActions.php:208`):
- `warehouse_id`: add `->live()` and required-unless-other validation — required when
  `warehouse_other` is blank (`->required(fn ($get) => blank($get('warehouse_other')))`), with a
  validation message like "Select a warehouse (or enter one under Other) — it's needed to order
  the part."
- `warehouse_other`: add `->live()` (so the required rule and the same-day link preview both react).
- The job form's own warehouse fields (`JobForm.php`) stay optional; only scheduling enforces it.
- The `needsWarehouse()` branch inside `orderParts()` stays — it now only serves legacy jobs
  scheduled before this change.

## Change 2 — same-day blocking confirm inside the schedule modal

Extend `scheduleFields()` (used by both `schedule()` and `reschedule()`, which spreads it at
`JobActions.php:87`) with a conditional "Order the part now" section. Note `scheduleFields()` is
currently static with no record context — either take the `Job` as a parameter
(`scheduleFields(Job $record)`, both call sites have it) or read the record via the closure `$record`
injection Filament provides inside component closures.

- Make `DatePicker scheduled_date` `->live()`.
- Section visible when: picked `scheduled_date` == today (PT, compare against
  `Timezone::now()->format('Y-m-d')`) AND `! $record->isPartsOrdered()`.
- Contents:
  - `Placeholder` reusing the existing live link preview — refactor `orderLinksHtml(Job, $get)`
    (`JobActions.php:706`) so it can be called from this section too (it already honors the
    form-selected `warehouse_id` over the saved one; `warehouse_other`/no-supplier renders the
    "dealer / custom supplier" note).
  - `Checkbox parts_ordered_confirm` — label "I've ordered the part", helper "Same-day job — the
    part must be ordered before we confirm the time with the customer."
    `->required(fn ($get) => <section visible>)`, `->visible(...)` same condition.
- In the `schedule()`/`reschedule()` action closures, after `applySchedule($record, $data)`:
  if `($data['parts_ordered_confirm'] ?? false)`, call
  `self::lifecycle()->markPartsOrdered($record, self::actor())`. (`applySchedule` passes named
  args to `JobLifecycle::schedule`, so the virtual checkbox never reaches persistence — no
  stripping needed.)

Because the checkbox is required while visible, a same-day schedule cannot submit — and therefore
no confirmation text goes out (`maybeSendTimeConfirmation` runs inside `schedule()`) — until
ordering is confirmed. Cancelling the modal schedules nothing. **Rejected alternative** (owner
should flag if preferred): commit the schedule but hold texts until a later mark-ordered — creates
a limbo where the customer never gets a confirmation if dispatch dismisses the prompt.

Same-day **reschedule** of an already-ordered job: section hidden (stamp is never reset — existing
behavior, keep).

## Change 3 — prompt after scheduling for ALL non-same-day jobs

Rework `notifyScheduled()` (`JobActions.php:107`):
- **Same-day** unordered jobs no longer need the prompt path — Change 2 stamps them at submit, so
  they naturally fall into the plain-success branch (`isPartsOrdered()` is true). No special case
  needed; just verify the flow.
- **Drop the `isWithinNextBusinessDay` gate** for the prompt: every scheduled-and-unordered job
  (next-day AND further out) now gets prompted, not just those within the next business day.
- **Preferred prompt: chain the existing order modal** instead of (or in addition to) the
  notification — in the `schedule()`/`reschedule()` action closures, inject the Livewire component
  (`function (Job $record, array $data, \Livewire\Component $livewire)`) and after success call
  `$livewire->replaceMountedAction('orderParts')` so dispatch lands directly in the shared
  `orderParts()` modal (links + "Mark ordered"). Requirements for this to work: the `orderParts`
  action must be registered on every component where `schedule`/`reschedule` are mounted (jobs
  table + view page — verify both; register it wherever those actions are).
  Closing the modal without marking is safe — the job stays on the Parts to Order dashboard/badge.
- **Fallback** if `replaceMountedAction` chaining misbehaves in Filament 4: keep the existing
  `sendOrderPartsPrompt()` persistent notification, just without the business-day gate. In either
  case the "Pick warehouse & order" notification branch is now legacy-only (new schedules always
  have a warehouse).
- `sendOrderPartsPrompt()` either becomes the fallback or is removed — don't leave both paths
  firing (a chained modal + a persistent notification for the same event is noise).

## No changes

Parts to Order page, `PartsOrderLinks`, `BusinessDays`, both migrations, warehouse form/enum,
`markPartsOrdered`, scopes — all stay as shipped.

## Tests — extend `tests/Feature/PartsOrderingTest.php`

1. **Schedule validation:** scheduling with neither `warehouse_id` nor `warehouse_other` is
   rejected; with `warehouse_other` only, accepted.
2. **Same-day blocking:** scheduling for today without `parts_ordered_confirm` fails validation;
   with it → job scheduled + `parts_ordered_at` stamped + time-confirmation text queued (i.e. the
   text only ever exists alongside the stamp). Same-day reschedule of an ordered job needs no
   confirm and keeps the stamp.
3. **Next-day/future prompt:** scheduling for tomorrow (and for +3 business days) stamps nothing
   and the job appears in `partsToOrder`; if the notification fallback is used, assert it fires
   for the further-out case too (the rev-1 test asserting NO prompt beyond next-business-day must
   be inverted).
4. Existing rev-1 tests keep passing (BusinessDays, links, scopes, idempotency).

Note: Filament form-validation behavior (conditional `required`) is hard to assert through pure
feature tests — test the action via Livewire (`livewire(ListJobs::class)->callTableAction(...)`)
as done elsewhere in the suite, or at minimum test the closure logic (`markPartsOrdered` called
iff confirm passed).

## Rollout notes

- No new migrations. Warehouse-required only affects new schedule/reschedule submissions; legacy
  warehouse-less scheduled jobs still get the picker inside the order modal.
- Behavior change dispatch will notice: same-day scheduling now has a mandatory extra checkbox,
  and every schedule of an unordered job opens the order prompt. Tell the dispatcher.
- Deploy = push to `master` per CLAUDE.md.
