# AI Scheduling — build spec

Feature: after intake, a dispatcher clicks **Schedule w/ AI**. The system reads the customer's
requested day/time, looks at each technician's real calendar load, and texts techs **one at a time in
priority order** proposing a specific slot. It reads their replies (Spanish or English), negotiates
when they counter, and once a tech agrees it assigns them, sets the date/time, and schedules the job
through the normal `JobLifecycle::schedule()` path. It also reads the customer's replies to the
welcome text and can re-target the slot mid-session.

Status: **built, all 8 phases, on `master`.** Nothing texts a real technician yet, by design — the
global `ai_scheduling_enabled` setting (Admin → Integrations → AI assist) and every technician's own
opt-in (Employee record → "AI scheduling texts") both default to off. Turning both on for one test
tech, with the session's tech mode left on `simulate`, is the safe way to start (see §10 rollout).

---

## 0. Non-negotiable design rules

These are the properties that keep this safe. Violating any of them is a bug, not a style choice.

1. **The LLM only ever classifies and extracts. It never writes an outbound message and never
   writes to a job.** Every outbound text is a `message_templates` row with merge vars (bilingual,
   ASCII-only, owner-editable, Spanish-review issues apply). Every write to a `Job` goes through
   existing `JobLifecycle` methods.
2. **Re-validate immediately before committing.** Between "tech said yes" and
   `JobLifecycle::schedule()`, re-check: session still running, job still unscheduled, no technician
   assigned by a human in the meantime, tech has no hard conflict, slot is still in the future. Any
   check fails → pause the session and alert dispatch. Never overwrite a human's work.
3. **A technician is mid-negotiation on at most ONE job at a time, globally.** This is what makes
   "inbound text from this phone" unambiguously resolvable to a session. Enforced by a partial
   uniqueness check in `TechOutreach`; a session that wants a busy tech skips to the next in
   priority order and notes it in the transcript.
4. **Low confidence escalates, it never guesses.** Below `min_confidence` (default 0.6), or intent
   `unclear`/`question` → pause that attempt and wait for a human (on the AI Scheduling page - no
   Telegram; see 6.3).
5. **Everything is recorded.** Every outbound, inbound, classification (raw JSON), model, token
   counts and cost lands in `ai_scheduling_turns`. The transcript is the product during testing.
6. **Three modes, per session:** `simulate` (nothing sends; replies typed into a console),
   `approve` (each outbound waits for a dispatcher click), `live`. Tech-side and customer-side
   autonomy are **separate** toggles so tech-side can go live while customer-side stays on approve.
7. **Simulate mode stops BEFORE the commit — it does not merely suppress the tech texts.**
   `Commit` calls `JobLifecycle::schedule()`, which puts the job on the real calendar *and* fires
   the customer's time-confirmation text plus their reminders. A "simulation" that texts a real
   customer their appointment is worse than no simulation at all. A simulated acceptance resolves
   the session to the distinct status `simulated`, recording what it *would* have booked, and never
   touches the job. (`approve` mode does commit for real — it gates outbound texts, not the work.)

---

## 1. Core abstractions

### 1.1 `SchedulingConstraint` (value object, stored as JSON on the session)

The unit of negotiation is **not an instant** — it's a constraint. This is what makes "the customer
can only do mornings" expressible, which is the whole point of the counter-offer flow.

```php
final class SchedulingConstraint
{
    public function __construct(
        public readonly string $date,          // Y-m-d, Pacific
        public readonly ?string $exactTime,    // 'H:i' when the customer named a specific time
        public readonly string $partOfDay,     // morning | afternoon | evening | any
        public readonly string $firmness,      // exact | window | after | flexible
        public readonly string $earliest,      // 'H:i' derived
        public readonly string $latest,        // 'H:i' derived
    ) {}

    public function admits(string $date, ?string $time): bool;   // does a proposed slot satisfy this?
    public function phrase(Language $lang): string;              // "Tuesday morning (8am-12pm)" / "el martes por la manana (8am-12pm)"
    public function toArray(): array;  public static function fromArray(array $a): self;
}
```

- `firmness = exact` → tech must hit `exactTime` (± `exact_tolerance_minutes`, default 0).
- `firmness = window` → any time in `[earliest, latest]` is acceptable (e.g. "morning").
- `firmness = after` → a LOWER BOUND only: `earliest` is the hour the customer named
  (`TimePreference::After` + `jobs.requested_after_time`), `latest` is close, `partOfDay` stays
  `any` because the window straddles the day parts. Reads as "Tuesday after 10am".
- `firmness = flexible` → any time that business day is acceptable ("ASAP", "tomorrow whenever").
- Day-part boundaries live in `config('glass.ai_scheduling.day_parts')`
  (`morning 08:00-12:00`, `afternoon 12:00-17:00`, `evening 17:00-19:00`).

**Built by `RequestedSlotParser`** from `jobs.requested_day` (already Y-m-d from the intake
DatePicker) + `jobs.requested_time_preference` / `jobs.requested_after_time` / free-text
`jobs.requested_time` ("2pm", "morning", "As soon as possible", "después de
las 3"). Deterministic regex/keyword pass first; unparseable strings fall through to the LLM
(`interpretRequestedTime`) and, failing that, default to `partOfDay: any, firmness: flexible`.
**The dispatcher always sees and can edit the resolved constraint in the launch modal** — the parser
is a convenience, not an authority.

### 1.2 `TechDayLoad` / `TechAvailability` (new domain service — nothing like it exists today)

Per the owner: a tech with an 8–10am job **can still take another morning job**; two morning jobs
means they're genuinely busy. So availability is a **soft load score**, not a binary conflict.

```php
final class TechAvailability
{
    /** Jobs the tech already has on that local date, bucketed by day part. */
    public function loadFor(Employee $tech, string $localDate): TechDayLoad;

    /** Hard conflict = an existing EXACT-TIME job that overlaps an exact-time request. */
    public function hasHardConflict(Employee $tech, SchedulingConstraint $c): bool;
}

final class TechDayLoad
{
    public int $morning; public int $afternoon; public int $evening; public int $total;
    public function inPart(string $part): int;
    public function bucket(SchedulingConstraint $c): string;  // open | light | busy
    /** "You already have 1 job that morning" — merge var for the offer text; '' when open. */
    public function noteFor(SchedulingConstraint $c, Language $lang): string;
}
```

- Source query: `Job::open()->whereNull('work_completed_at')->whereNotNull('technician_id')
  ->scopeScheduledOnLocalDate($date)` — the same shape `TechReminderScheduler:44-51` already uses.
  Include scheduled `WarrantyClaim` visits too (they occupy the tech).
- `bucket`: 0 jobs in that part → `open`; 1 → `light`; `>= config busy_threshold` (default 2) → `busy`.
- **Ordering of techs to contact:** strict `Employee::scopeOrderByPriority()`, but in two passes —
  all `open`/`light` techs in priority order first, then `busy` techs in priority order. Techs with a
  `hasHardConflict` are excluded entirely (with a transcript note). This honors "one at a time by
  priority" while not burning the #1 spot on someone who already has two morning jobs.
- **Unless the dispatcher set their own order** (2026-07-28). The launch modal's "Will contact, in
  order" list is a drag-to-reorder repeater — rows can be dragged, and a tech can be removed from
  (or added back to) the list. That list is stored on `ai_scheduling_sessions.tech_order` and, when
  present, **wins outright**: its order is followed as-is and the busy-to-the-back pass is skipped
  (they were looking at this job when they dragged the names — re-sorting under them would make the
  list they just read a lie). Opt-in, phone and hard-conflict exclusions still apply. The **global
  default** ranking behind the list is now drag-to-reorder too, on **Admin → Tech Roster**
  (`->reorderable('priority')`, per pool, which renumbers the whole pool 1..N).
- The `busy`/`light` state is surfaced **in the offer text itself** via `{load_note}` — exactly as
  requested: *"Ya tienes 1 trabajo mañana por la mañana, pero ¿puedes hacer uno más?"*

---

## 2. Data model

### 2.1 `ai_scheduling_sessions` — one per attempt-to-schedule-a-job

| column | type | notes |
|---|---|---|
| `job_id` | FK jobs | one **live** session per job (enforced in code, not a DB unique — closed sessions accumulate) |
| `status` | string | `draft` `running` `paused` `scheduled` `simulated` `exhausted` `canceled` — `simulated` means the negotiation succeeded in simulate mode and the job was deliberately **not** written (see §0.7), so "did this touch a real job?" is answerable from one column |
| `constraint` | json | `SchedulingConstraint::toArray()` |
| `original_constraint` | json | what it started as, before any customer re-target |
| `tech_mode` | string | `simulate` `approve` `live` |
| `customer_mode` | string | `simulate` `approve` `live` `off` |
| `tech_order` | json, nullable | employee ids in the order the dispatcher dragged them into on the launch modal. **Allow-list, not just a sort** — a tech dragged off it is not contacted for this job. Null = follow the roster ranking (2026-07-28) |
| `warehouse_id` / `warehouse_other` | | collected in the launch modal; null when `labor_only` |
| `current_attempt_id` | FK, nullable | |
| `retarget_count` | int | customer-driven constraint changes; capped |
| `paused_reason` | string, nullable | |
| `resolved_technician_id`, `resolved_at`, `scheduled_at_utc` | | outcome |
| `created_by` | FK employees | |
| `cost_cents_micro` | int | running LLM spend, for the "is this worth it" question |

### 2.2 `ai_scheduling_attempts` — one per technician contacted

`session_id`, `employee_id`, `position` (1,2,3…), `status`
(`queued` `asked` `negotiating` `confirming_slot` `awaiting_warehouse` `accepted` `declined`
`no_response` `skipped` `escalated` `superseded`), `reiterations` (int),
`load_bucket` (snapshot at ask time), `asked_at`,
`deadline_at` (ask + response window), `proposed_time` (H:i — the slot we offered back while
`confirming_slot`, see §4.2.1), `agreed_time` (H:i), `last_inbound_at`, `skip_reason`.

### 2.3 `ai_scheduling_turns` — the transcript + audit trail

`session_id`, `attempt_id` (nullable), `party` (`tech`|`customer`|`system`), `direction`
(`out`|`in`|`note`), `employee_id`/`customer_id`, `scheduled_message_id` (out) /
`inbound_message_id` (in), `body`, `classification` (json — raw LLM output), `intent`,
`confidence`, `model`, `input_tokens`, `output_tokens`, `cost_micro`, `used_llm` (bool — false for
deterministic fast-path hits), `created_at`.

Plus `approval_status` (null | `pending` | `approved` | `skipped`) and `pending_payload` (json:
template key + extra merge vars). **"Approve each" mode parks an outbound as a turn** rather than
in a second table — the turn already carries the rendered body, recipient and attempt, so the
transcript and the approval queue are the same rows. `AiSchedulingService::approveOutbound($turn)`
is what the panel's Send button calls. On an outbound turn, `intent` holds the template key.

### 2.4 Column additions

- `employees.ai_scheduling_enabled` — bool, default **false**. Per-tech opt-in, on the Employee form
  next to "End-of-day summary texts". A tech who is not opted in is never contacted by the AI.
- `inbound_messages.employee_id` — nullable FK. `InboundMessageService` currently resolves inbound
  only to `Customer` by E.164; teach it to also try `Employee.phone`. Valuable independent of this
  feature (Incoming Texts can then show "from Jose (tech)").

### 2.5 Settings / config

- Setting `ai_scheduling_enabled` (`'0'`/`'1'`) — global kill switch, on the **AI reconciliation
  assist** Integrations tile (rename it "AI assist"), reusing the existing encrypted
  `anthropic_api_key`. Add a `ai_scheduling_default_mode` select.
- `config/glass.php` → new `ai_scheduling` block: `day_parts`, `busy_threshold` (2),
  `response_window_minutes` (**90** — a silent tech gets an hour and a half before the sweep moves
  on; 20 → 120 on 2026-07-26, then 120 → 90 on 2026-07-27, both per the owner),
  `same_day_response_window_minutes` (10, unchanged — a job being scheduled for today can't wait
  90 minutes per tech), `max_techs_per_session`
  (6), `max_reiterations` (1), `slot_confirm_window_minutes` (**20** — how long a tech gets to push
  back on the concrete slot we proposed before we book it, §4.2.1), `max_retargets` (2),
  `warehouse_ask_window_minutes` (**30** — how long a booked tech has to say which warehouse, §4.5),
  `min_confidence` (0.6),
  `model` (`claude-haiku-4-5-20251001`), `exact_tolerance_minutes` (0),
  `active_hours` (**`06:00`–`22:30`**, §2.6).

### 2.6 Contact hours — the clock is gated, the conversation is not

`active_hours` (6:00am–10:30pm Pacific, owner 2026-07-27) governs **`AiSchedulingService::advance()`
and the sweep** — everything driven by the clock rather than by a person. Outside the window a
running session simply **holds where it stands**: no cold text to the next technician, no writing a
silent one off as `no_response`, no booking a slot on silence (§4.2.1), no expiring a session whose
day has passed (that supersedes a live attempt, which texts that tech). The sweep keeps ticking and
picks the session back up at 6:00am.

⚠️ **A reply is never gated.** "Unless a response IS sent after 10:30pm, then you can engage" — a
technician who texts at 11pm is answered at 11pm, and everything that reply triggers (a reiterate, a
slot read-back, the commit itself, the warehouse ask) runs with it. Only silence waits for morning.
`outreachAllowedNow()` / `nextContactWindowOpensAt()`.

A **first** hold writes one transcript note naming the window and when it reopens, then stays quiet
(the sweep re-enters every two minutes all night). This is deliberate: an earlier 7am–8pm version of
this gate was **deleted on 2026-07-26** for silently parking a live 8:50pm session with nothing on
the transcript to explain it. The 10:30pm end covers that evening case; the note covers the rest.

---

## 3. Message templates (new `TemplateKey` cases)

⚠️ `TemplateKey` has **four exhaustive `match()`s with no default arm** — `label()`, `category()`,
`messageType()`, `requiredVars()` — plus `audience()`/`preferredProvider()`. Every new case must touch
all of them. All bodies **ASCII punctuation only** (no curly quotes, no em-dashes) per the GSM-7 rule,
seeded in `MessageTemplateSeeder::bodies()` (en + es) and inserted live by a migration calling
`seedMissing()`.

| key | audience | category | purpose | required vars |
|---|---|---|---|---|
| `tech_ai_slot_offer` | Tech | TechCrew | Proposes a specific slot. Carries `{load_note}`. | `slot_phrase` |
| `tech_ai_slot_reiterate` | Tech | TechCrew | "The customer can only do {constraint_phrase}. Can you do that? If not, no problem, just tell me." | `constraint_phrase` |
| `tech_ai_slot_confirm` | Tech | TechCrew | "Great - we will book it for {slot_phrase}. Confirming that works." Sent after a tech agrees to a whole part of day (§4.2.1). | `slot_phrase` |
| `tech_job_confirmation` | Tech | TechCrew | The full job card that CLOSES the booking - name, phone, address, time, vehicle, part #, warehouse. Shared with dispatcher-booked jobs (CLAUDE.md "Tech job confirmation"), not AI-specific. Replaced the thin `tech_ai_assigned` on 2026-07-30. | `scheduled_date_time` |
| `tech_ai_warehouse_ask` | Tech | TechCrew | "Which warehouse should we order the part from?" — straight after the booking (§4.5). | — |
| `tech_ai_warehouse_set` | Tech | TechCrew | "Got it - we will order the part from {warehouse}." | `warehouse` |
| `tech_ai_filled` | Tech | TechCrew | "Thanks, this one already went to another tech." | — |
| `customer_ai_time_check` | Customer | Scheduling | Used only when re-targeting: "Can we do {slot_phrase} instead?" | `slot_phrase` |

New merge vars in `TemplateRenderer::SUPPORTED_VARS`: `slot_phrase`, `constraint_phrase`,
`load_note`, `tech_proposed_time`, `warehouse`. (The renderer rejects unknown `{tokens}` at the
editor, so these must be registered or the templates won't save.)

**No new customer confirmation text on the happy path** — `JobLifecycle::schedule()` already fires
`TimeConfirmation` / `TimeConfirmationCalibration` and queues the reminders. Do not double-text.

Do **not** reuse `tech_job_availability`: it asks an open-ended "what time works for you?", and its
idempotency key (`job:{id}:tech:{id}:tpl:{key}`) locks it to once-per-job-per-tech forever, which
breaks a multi-turn negotiation. AI-path outbounds use keys of the form
`ai:{session}:{attempt}:{turn}:tpl:{key}`.

---

## 4. Services

```
app/Services/AiScheduling/
  AiSchedulingService.php     — public API: start(), advance(), handleTechReply(), handleCustomerReply(),
                                pause(), resume(), skipTech(), cancel(), takeOver()
  RequestedSlotParser.php     — Job -> SchedulingConstraint (deterministic, LLM fallback)
  TechAvailability.php        — loadFor(), hasHardConflict()
  TechDayLoad.php             — bucket(), noteFor()
  TechOutreach.php            — ordered tech list, the one-live-attempt-per-tech invariant, sends the offer
  ReplyInterpreter.php        — deterministic fast-path + LLM; returns ReplyIntent
  WarehouseResolver.php       — "which warehouse?" answer -> a real Warehouse row (§4.5)
  LlmClassifier.php           — the only file that talks to Anthropic (interface + Http impl)
  ReplyIntent.php             — value object
  SchedulingConstraint.php
  Commit.php                  — the re-validate-then-JobLifecycle::schedule() step
```

### 4.1 `ReplyInterpreter`

**Fast-path first (free, instant, ~70% of replies).** Whole-message match, accent- and
case-insensitive:
- accept: `si`, `sí`, `yes`, `ok`, `okay`, `listo`, `dale`, `claro`, `perfecto`, `va`, `simon`,
  `👍`, `sale`, `de acuerdo`, `yes i can`, `i can`
- decline: `no`, `no puedo`, `nel`, `estoy ocupado`, `no puedo ese dia`, `cant`, `can't`, `busy`
Anything longer or ambiguous → LLM.

**Reactions — `reactionSentiment()` (2026-07-27, owner).** A tech answering by TAPPING a reaction is
the single most common reply the fast-path used to miss, and missing it is expensive: the job silently
goes to the next tech. What actually arrives is not a bare emoji — Quo relays an iPhone tapback as the
emoji, invisible padding, and the whole original message quoted back:

```
👍​ a “ Hola, tengo un trabajo para lunes, 27 de julio. ... ”
```

So the detector strips zero-width/variation-selector padding, then accepts either (a) a message that is
nothing but reaction emoji, or (b) a **≤40-character header followed by an opening quote** — which
covers `👍 a "..."`, `👍 to "..."`, `Liked "..."`, `Loved "..."`, `Le gustó "..."`. 👍👌✅❤ read
positive, 👎❌ read negative (negative wins a tie — "disliked" *contains* "liked"), and everything
else — a laugh, an emphasis, a `👍 but only at 2pm` — returns null and goes to the LLM, which now
carries a matching rule in its prompt as a backstop for relay formats we don't know.

**A reaction accept is a WEAKER accept**, and the state machine treats it as one — see 4.2.1.

**LLM call** — same shape as `LlmMatchSuggester` (raw `Http`, no SDK, `x-api-key` from `Setting::get`,
`anthropic-version: 2023-06-01`, 30s timeout, manual status check, `preg_match('/\{.*\}/s')` JSON
extraction, log-and-return-null on any failure). Model `claude-haiku-4-5-20251001`, `max_tokens: 200`.

Prompt (single user message, ~350 input tokens):

```
You classify a technician's SMS reply to a job offer. The tech may write in Spanish or English.
Output ONLY a JSON object, no prose.

Today is {today} (America/Los_Angeles).
The customer requires: {constraint_description}
We texted the tech: "{our_last_message}"
{prior_turns}
The tech replied: "{reply}"

{"intent":"accept|counter|decline|question|unclear",
 "date":"YYYY-MM-DD or null","time":"HH:MM 24h or null",
 "asks":"<their question, or null>","confidence":0.0-1.0,"reason":"<8 words>"}

Rules:
- "accept" ONLY if they clearly agree. If they name any different time, use "counter" with that time.
- "decline" if they cannot do it at all, or cannot do that day.
- "question" if they are asking us something (which shop, what car, how much).
- "unclear" if you cannot tell. Do not guess.
```

The model **never** decides whether the counter is acceptable — `SchedulingConstraint::admits()` does
that in PHP. That keeps the flexibility rule deterministic and testable.

### 4.2 The tech negotiation state machine

```
ask tech #N  ──(no reply before deadline)──▶ mark no_response ──▶ ask tech #N+1
     │
     ├─ accept ─────────────────────────────▶ CONFIRM THE SLOT (§4.2.1) ──▶ COMMIT (§4.4)
     │
     ├─ counter ─┬─ constraint->admits(counter)? ──yes─┬─ named a time ──▶ COMMIT at that time
     │           │                                     └─ no time ──────▶ CONFIRM THE SLOT
     │           └─ no ─┬─ reiterations < max ──▶ send tech_ai_slot_reiterate, stay on this tech
     │                  └─ else ───────────────▶ mark declined ──▶ ask tech #N+1
     │
     ├─ decline ────────────────────────────▶ ask tech #N+1
     ├─ question ─┬─ attempt is confirming_slot? ──yes──▶ BOOK the proposed slot (§4.2.2)
     │            └─ no ─────────────────────────────────▶ escalate (pause attempt, no alert - see 6.3)
     └─ unclear / confidence < min ─────────▶ escalate
```

This is the owner's stated behavior verbatim: a tech who counters with an afternoon when the customer
needs a morning gets *pushed back on with the same tech* ("the customer can only do mornings — can you
do that?"), and only when they still don't offer something compatible, or say no, does it move on.
`max_reiterations` defaults to 1 (so: offer → counter → reiterate → counter → move on).

When all techs are exhausted → session `exhausted`, Open Task for dispatch (no Telegram - see 6.3).

### 4.2.1 Slot confirmation — "we will book it for 8am-10am" (2026-07-26, owner)

**Jobs are booked as 2-hour arrival windows, so "yes, Monday morning" is not yet an answer.** A tech
who agrees to a whole part of day used to be booked silently at the start of it — which is how
someone who meant 10am finds out they have an 8am. Instead, `AiSchedulingService::acceptOrConfirmSlot`
branches on what they actually said:

- **They named a time the customer can do** ("I can do 9 to 11") → that IS the answer. Book it,
  no extra text. Owner's rule: use the tech's time as long as it's still within the window.
- **Nothing left to narrow** — an `exact` request, or a day part already as short as the booking
  window (evening, 5-7pm) → book it.
- **Otherwise** → attempt goes to `confirming_slot`, holding the proposed time in
  `ai_scheduling_attempts.proposed_time`, and we text `tech_ai_slot_confirm` with the **earliest**
  2-hour slot in the window (go early first: morning → 8am-10am). Then:
  - they confirm ("sure, 8-10 works") → book it;
  - they counter with another time the constraint admits → **their time wins**, book that;
  - they counter with something outside it → the normal reiterate/pushback path, unchanged;
  - **they say nothing for `slot_confirm_window_minutes` (20) → book what we proposed.** Silence
    here is NOT the silent-tech timeout: they already took the job, so the sweep books it rather
    than moving to the next tech (`advance()` branches on `confirming_slot` before `no_response`).
    Capped by the response window, so a same-day job waits 10 minutes, not 20.

`confirming_slot` is a LIVE attempt status, so the one-live-attempt-per-tech invariant still holds
while we wait. `proposed_time` is deliberately its own column rather than an early write to
`agreed_time` — that column means "this is what they signed up for" and is only written on commit.

**A thumbs up always gets read back — `tech_ai_reaction_confirm` (2026-07-27, owner).** A tap is an
ambiguous yes (it can just as easily mean "got it"), so a reaction accept goes to `confirming_slot`
**even when there is nothing to narrow** — the one case that skips the branch above. Its copy leads
with what we understood rather than only naming the slot:

> It sounds like you are saying yes to this job. We will book it for {slot_phrase}. Does that work for you?

From there it is the ordinary `confirming_slot` path: a yes (typed *or* tapped) books it, a counter the
constraint admits wins, silence through the window books what we proposed — and the booking then asks
which warehouse (§4.5) exactly as a typed acceptance does. `AiSchedulingService` recomputes
`reactionSentiment()` on the raw body rather than reading it off the intent, so an accept the *model*
classified from an unfamiliar relay format is read back too.

`SchedulingConstraint::bookedPhrase($time, $lang)` renders the concrete slot ("Wednesday, July 22
from 8am to 10am") as opposed to `phrase()`, which describes the customer's whole window. It is what
`tech_ai_slot_confirm` and the Telegram booking alert both say now — the tech's record of when to
show up should be the slot, not "in the morning (8am-12pm)". The closing `tech_job_confirmation`
card reaches the same place by a different route: its Time line is `{scheduled_date_time}`, read off
the job Commit just booked, so it names the concrete slot without needing the phrase passed in.

### 4.2.2 A question at `confirming_slot` never un-books the tech (2026-07-31)

**A question does not retract an acceptance.** `AiSchedulingService::handleQuestion` branches on the
attempt status: a question from a tech who has NOT taken the job still escalates (§4.2 — we never
improvise at a technician), but once the attempt is `confirming_slot` the tech has already accepted
and the only thing still open is which 2-hour window we write down. So the question books the
proposed slot and lands on the transcript, exactly as **silence** at the deadline already does.

- ⚠️ **Why it matters:** before this, a reply was strictly WORSE than no reply. Say nothing and the
  sweep books your slot; say "Ok, can you check the door at Pilkington?" and the session paused with
  the job still off the calendar. Real case **SFV-2026-0037** (2026-07-31): the tech answered `Si`,
  we read back 2:30pm-4:30pm, he replied `Ok pudes chekar la puerta en pilkinton x favor` — an
  accept plus an unrelated parts question — and the booking was lost.
- **The question is usually the warehouse answer arriving early.** Before committing,
  `applyVolunteeredWarehouse()` runs the body through the SAME `WarehouseResolver` as a real answer
  to `tech_ai_warehouse_ask` (§4.5). A hit is written with `JobLifecycle::setPartsWarehouse()`
  **before** the commit, so `maybeAskWarehouse()` then skips itself on its own `warehouse_id` guard
  — we never ask what they just told us — and the closing `tech_job_confirmation` card names the
  real supplier instead of "waiting on you to select warehouse". A miss simply books and lets the
  ordinary warehouse ask run, unchanged.
- Skipped in **simulate mode**, which resolves before the commit and writes nothing to the job.
- This is §4.5's rule ("once booked, nothing pauses") starting one step earlier. A question at any
  EARLIER status — offered, negotiating — is untouched and still escalates.

### 4.3 Customer replies (two-sided)

`InboundMessageService` routes a customer inbound into their live session (most recent if several).
Classified by the same `LlmClassifier` with a customer-flavored prompt →
`confirms | proposes_new_time | question | other`.

- **`proposes_new_time`** → build a new constraint. If `retarget_count < max_retargets`:
  update the session constraint, mark the in-flight tech attempt `superseded` (text them
  `tech_ai_filled`), restart outreach from tech #1 against the new constraint, write a `system` turn,
  and **always post a Telegram notice** ("AI re-targeted job #123: Tue morning → Wed afternoon, per
  customer text"). Over the cap → pause + escalate.
- **`confirms`** → note it, continue.
- **`question` / `other`** → pause the session, Telegram + Open Task. The AI never answers a customer
  free-form in phase 1.
- If `customer_mode = approve`, any customer-facing outbound (`customer_ai_time_check`) waits for a
  dispatcher click even when the tech side is `live`.
- ⚠️ **`customer_mode = off` never pauses or escalates on a customer reply** (2026-07-26, owner). The
  AI was never going to text that customer — dispatch owns the thread — so pausing the tech
  negotiation and paging the group chat over a conversation the AI isn't in was pure noise. Every
  customer-driven escalation path (unreadable reply, empty counter, question/other, over the retarget
  cap) routes through `AiSchedulingService::customerEscalate`, which with the mode off writes a system
  note ("Customer texting is off, so leaving this to dispatch: …") and lets the session run on. A
  clean, readable **counter still re-targets** the session either way — that costs no customer text
  and keeps the tech outreach aimed at the time the customer actually wants.

### 4.4 Commit (`Commit::execute`)

Re-validate, in this order, aborting to `paused` on the first failure:
1. session `status === running`
2. `job->scheduled_at === null` and `job->technician_id === null` (no human beat us to it)
3. `job->status` is `PendingSchedule`
4. `! TechAvailability::hasHardConflict($tech, $constraint)`
5. resolved instant is in the future and inside business hours
6. tech still `active` and `ai_scheduling_enabled`

Then, inside a transaction:
```php
$this->lifecycle->schedule(
    job: $job,
    scheduledAtUtc: $instantUtc,
    actor: $session->createdBy,          // the dispatcher who launched it; keeps activity-log attribution real
    technicianId: $tech->id,
    warehouseId: $session->warehouse_id,
    warehouseOther: $session->warehouse_other,
    exactTime: $constraint->firmness === 'exact',
    windowHours: $constraint->firmness === 'exact' ? null : $windowHours,
    notifyTech: false,                   // the session sends the tech's card itself, below
);
```
Then: text the winner `tech_job_confirmation` (the full job card); text any other tech who accepted
late `tech_ai_filled`; session → `scheduled`; Filament notification to the launching dispatcher;
activity log `ai_scheduled` on the job.

⚠️ **`notifyTech: false` is load-bearing.** `JobLifecycle::schedule()` normally texts the tech their
job card itself, so without it the tech gets the card twice. The session sends it instead of letting
the lifecycle do it, so the card lands as a turn in the negotiation transcript and simulate/approve
modes still gate it. The customer's time confirmation is NOT suppressed with it - the AI never owned
that one.

⚠️ **Warehouse:** `JobActions` makes warehouse `required` when scheduling. The launch modal collects
it upfront (prefilled from the job, hidden when `labor_only`) but does **not** require it — when it
is left empty the AI asks the tech who took the job, after booking (§4.5).

### 4.5 "Which warehouse?" — the post-booking follow-up (2026-07-26, owner)

**Once the job is booked, ask the tech who took it where to order the part from.** It's their run to
the shop, so it's their call, and asking now — in the same thread, while they're looking at their
phone — beats a dispatcher guessing or chasing them later.

- **Fires from `acceptFrom()` after a successful `Commit`**, never in simulate mode (which resolves
  before the commit and writes nothing). Skipped when there is nothing to ask about: `labor_only`,
  a job whose source was already chosen (launch modal or intake), or no active warehouses.
- The attempt moves to **`awaiting_warehouse`** with a `deadline_at` of
  `warehouse_ask_window_minutes` (**30**). This is a **LIVE** attempt status: it is what routes the
  answer back to this job, and it keeps that tech off other sessions' offer lists while the question
  is open — which is why the window is short and the sweep always closes it.
- `handleTechReply()` checks for `awaiting_warehouse` **before** the "session must be running" guard
  — by then the session is (correctly) `scheduled`.
- **`WarehouseResolver`** turns the answer into a real row: deterministic scoring on each
  warehouse's distinctive words plus its supplier's nicknames (`igc`, `mygrant`), requiring a
  *unique* high scorer — so "mygrant san fernando" resolves and a bare "mygrant" honestly does not.
  Only then does it ask the model, which answers with a **1-based index into the real list**
  (`LlmClassifier::classifyWarehouseReply`) and so can never name a supplier we don't buy from.
- The write goes through **`JobLifecycle::setPartsWarehouse()`**, which refuses to overwrite a source
  a human picked while we waited, and activity-logs `warehouse_set`. Then `tech_ai_warehouse_set`
  confirms it back to the tech.
- **Nothing here may block, pause or escalate** — the job is already on the calendar. An unreadable
  answer, no answer, or a deadline that lapses just closes the attempt to `accepted` with a
  transcript note and leaves the parts source empty; the Order-part modal already asks for it, and
  Parts to Order flags it as "— pick —". We never re-ask and never guess.
- Expiry is swept by **`AiSchedulingService::closeExpiredWarehouseAsks()`**, called from `sweep()`
  *outside* the running-sessions loop (the session is `scheduled`, so the normal loop skips it). It
  is the third counter in the sweep's return array.

---

## 5. Entry points & UI

### 5.1 Launch — "Schedule w/ AI"
Added to `JobActions` (job view actions menu) and `SendWelcome` (the post-intake screen, next to the
existing "Text techs" action). Visible when: global setting on, key present, job unscheduled and open,
at least one opted-in tech in the entity pool, `manage_jobs`.

Modal shows:
- **Resolved constraint** — date picker + firmness radio (exact / part of day / after a time / any
  time that day) revealing an exact-time picker, a part-of-day select or an after-time select,
  prefilled by `RequestedSlotParser`, with the raw `requested_daytime_raw` shown as helper text.
- **Warehouse** (hidden if `labor_only`).
- **Tech order preview** — read-only list: `#1 Jose — free that morning`, `#2 Luis — 1 job that
  morning`, `#3 Marco — 2 jobs that morning (busy)`, `Carlos — skipped, not opted in`.
- **Mode**: tech-side + customer-side selects, defaulting to `ai_scheduling_default_mode`.
- **Response window** minutes.

### 5.2 `Operations → AI Scheduling` page
Table of sessions (live first). Per-row: job, customer, constraint phrase, current tech, state, elapsed,
cost. Row click → **transcript panel**: chat bubbles (tech left, customer right, system notes centered),
each showing the classified intent + confidence + whether the LLM was used, oldest first.
⚠️ Style with **inline styles + `x-filament::*` components, never bare Tailwind** — there is no custom
Filament theme compiled, per the repo's notes-feed lesson.

Actions: **Pause / Resume**, **Skip this tech**, **Take over** (cancels the session and opens the normal
schedule modal prefilled with the constraint + last agreed time), **Cancel**.
In `simulate` mode the panel gains a **reply console**: pick tech or customer, type what they'd say,
submit → runs the real interpreter and advances the real state machine, sending nothing. This is the
primary internal test harness.

### 5.3 Job view
An "AI scheduling" badge/section when a session is live, linking to the transcript. Turns interleave
into `JobTimeline` (new 🤖 icon) so the job still reads as one story.

---

## 6. Wiring

### 6.1 Inbound routing
In `InboundMessageService::handle()`, after the existing customer lookup:
1. Try `Employee::where('phone', ...)` on the E.164 → set `inbound_messages.employee_id`.
2. **Opt-out first, always**: if `OptOutDetector::isOptOut()` and the sender is an Employee →
   flip `ai_scheduling_enabled = false` on that employee, Telegram notice, stop. (The existing
   customer opt-out path is untouched.)
3. Else if employee → `AiSchedulingService::handleTechReply()`.
4. Else if customer with a live session → `AiSchedulingService::handleCustomerReply()`.
Everything is dispatched to a **queued job** (`ProcessAiSchedulingReply`) so the webhook stays fast and
an LLM timeout can never 500 a provider webhook.

### 6.2 Cron — `ai:advance-sessions`, every two minutes
`routes/console.php`, `->withoutOverlapping()->timezone(config('glass.timezone'))`, preceded by the
house-style comment block. Handles only time-driven transitions (replies are push-driven):
response-window timeouts → next tech; sessions whose target date has passed → auto-cancel (silently);
sessions held outside `active_hours` → released at 6:00am (§2.6). No-op when the setting is off.

### 6.3 Telegram — exactly ONE alert (2026-07-26, owner)
Shared bot + `telegram_alerts_enabled` gate, per the repo convention — **no new bot, token, chat or
enable flag**. AI scheduling posts **one** message and no others: **"🤖 AI scheduling booked a job"**,
sent from `acceptFrom()` right after a successful `Commit`, naming the job, tech and agreed slot — the
point is that a job went on the real calendar with no human involved, so someone can spot-check it.
- The alerts for **escalated / exhausted / re-targeted / expired were all removed.** They fired on
  states that are already plain to see on the AI Scheduling page, and the group chat is for things
  that need someone to move. `AiSchedulingService::alert()` still exists — it just has one caller now.
- **A simulated booking never alerts.** `MODE_SIMULATE` resolves before `Commit`, so nothing was
  written to a job and there is nothing to spot-check.

---

## 7. Cost

Haiku 4.5, ~350 in / ~80 out per classification ≈ **$0.0008/message**. A busy day of 50 jobs × ~3
classified replies ≈ **$0.12/day, ~$4/month** — and the deterministic fast-path removes ~70% of those
calls, so realistically ~$1.50/month. Per-turn token counts are stored so the real number is visible on
the session list.

An open-source model (Llama via Groq/Together) would save a couple of dollars a month at the cost of a
new integration, new failure modes, and weaker Spanish/Spanglish comprehension. Not worth it now —
`LlmClassifier` is a one-method interface, so swapping later is a contained change.

---

## 8. Build order

1. ✅ `SchedulingConstraint`, `RequestedSlotParser`, `TechAvailability`/`TechDayLoad` + unit tests.
2. ✅ Migrations, models, `employees.ai_scheduling_enabled`, `inbound_messages.employee_id`,
   settings, config block.
3. ✅ Templates + `TemplateKey` cases + merge vars + seeder + seed-missing migration.
4. ✅ `LlmClassifier` + `ReplyInterpreter` (fast-path + LLM) with a fake implementation for tests.
5. ✅ `TechOutreach`, `AiSchedulingService` state machine, `Commit`.
6. ✅ Inbound routing + `ProcessAiSchedulingReply` + `ai:advance-sessions` cron.
7. ✅ Launch modal (`JobActions::scheduleWithAi()`, mirrored on `SendWelcome`) + the "AI Scheduling"
   Operations page (session list + transcript panel + Pause/Resume/Skip/Cancel/Take-over controls +
   the simulate reply console — `AiSchedulingService::simulateTechReply()` /
   `simulateCustomerReply()`) + per-turn Approve/Don't-send buttons for "approve each" mode
   (`approveOutbound($turn)`).
8. ✅ Job-view "AI scheduling" badge (visible only while a session is running/paused, linking to the
   transcript — `Job::liveAiSchedulingSession()`), `JobTimeline` interleaving (🤖, outbound/inbound
   turns only — internal system notes stay on the transcript), and the Telegram alerts, which turned
   out to already exist in phase 5's
   `AiSchedulingService` as inline instant alerts — same pattern as
   `WarrantyService::postOpenedAlert` — rather than needing separate builder classes, since they
   fire at the moment of the event rather than on a cron digest.

All 8 phases are covered by tests: `AiSchedulingConstraintTest`, `AiSchedulingTechAvailabilityTest`,
`AiSchedulingModelsTest`, `AiSchedulingTemplatesTest`, `AiSchedulingReplyInterpreterTest`,
`AiSchedulingFlowTest`, `AiSchedulingCommitTest`, `AiSchedulingInboundTest`, `AiSchedulingUiTest`,
`AiSchedulingJobViewTest`.

## 9. Tests

- `AiSchedulingConstraintTest` — parsing "2pm" / "morning" / "ASAP" / "después de las 3"; `admits()`.
- `TechAvailabilityTest` — day-part bucketing, the "1 job that morning is still fine, 2 is busy" rule,
  hard exact-time conflicts, warranty visits counting as load.
- `AiSchedulingFlowTest` (fake classifier) — accept → slot confirmation → book; a confirmation
  countered with another in-window time books the tech's time; an unanswered confirmation books the
  proposed slot without moving to the next tech; counter-that-fits → commits at the countered time;
  counter-that-doesn't → reiterate → second bad counter → next tech; decline; no-response timeout;
  exhaustion; low confidence → escalate.
- `AiSchedulingCommitTest` — every re-validation abort path, especially "a human scheduled it first".
- `AiSchedulingInboundTest` — tech phone routing, tech STOP disables their opt-in, one-live-attempt
  invariant, customer re-target.
- `AiSchedulingReplyInterpreterTest` — recorded real EN/ES/Spanglish replies against a mocked HTTP
  response, plus fast-path coverage.
- `AiSchedulingSimulateTest` — simulate mode sends zero `scheduled_messages` rows.
- `AiSchedulingWarehouseTest` — the post-booking ask (§4.5): who gets asked and who doesn't
  (labor-only, warehouse already set), a named warehouse resolving deterministically with no API
  call, an ambiguous one falling through to the model's index, an out-of-range index being ignored,
  never overwriting a human's pick, the sweep closing an unanswered ask, and the tech staying off
  other sessions while it's open.

## 10. Rollout

1. `simulate` on real jobs, driving replies by hand through the console — validates the state machine
   and the prompt with zero risk.
2. `approve` with one opted-in tech (Alex), tech-side only, customer-side `off`.
3. Tech-side `live` for one entity, customer-side `approve`.
4. Full live. Global kill switch and per-tech opt-in stay as the emergency brakes throughout.
