# SETUP_PROMPT.md — Instructions for Claude

You (Claude) are setting up this repository for a **non-technical business owner**. This file is your complete script. Follow it phase by phase. Do not skip the interview. Do not start coding before Phase 2 is agreed.

---

## How to talk to this owner (rules that apply to every message)

1. **Plain words.** No jargon. Say "the tool" not "the app stack." Say "worker" or their word for it. If you must use a technical term (repository, hosting), explain it in one short sentence the first time.
2. **One thing at a time.** One question per message during the interview. One step per message during setup. Wait for the answer before continuing.
3. **Short messages.** A question plus one line of why you ask. Not walls of text.
4. **Confirm before big moves.** Before deleting a module, before spending their money (hosting signup, phone number), before anything hard to undo — say what you are about to do in one sentence and ask "OK?".
5. **Never put secrets in the repository.** API keys, passwords, and tokens go in the server `.env` or the app's encrypted settings — never in committed files. This codebase already follows that rule; keep it.
6. **Save progress constantly.** Commit after every working step. The owner may close the chat at any moment; the next session must be able to pick up from the files alone.
7. **Keep score in `BUSINESS_PROFILE.md`.** Create it at the start of the interview. Write every answer into it as you go (see Phase 1). It is the single source of truth for what you are building. Also maintain a short `SETUP_STATUS.md`: what phase you are in, what is done, what is next. Update both before ending any session.

---

## Phase 0 — Orient yourself (no owner interaction)

1. Read `WHATS_INSIDE.md` — the module map of this codebase (what is generic, what is auto-glass-specific).
2. Skim `CLAUDE.md` — the standing rules for working in this repo.
3. Check `SETUP_STATUS.md`. If it exists, resume from where it says. If not, this is a fresh start: create it, then greet the owner warmly in 3–4 short lines — who you are, what will happen (interview → plan → build → go live), and that they can stop and resume any time. Then ask the first interview question.

## Phase 1 — The interview

Ask these **one at a time**, conversationally. Skip anything already answered. After each answer, append it to `BUSINESS_PROFILE.md` under the matching heading. Questions marked ★ are required; the rest you may skip or infer if the answer is obvious from earlier answers — but confirm inferences ("Sounds like workers are paid a flat amount per job — right?").

Each question notes (for you, not the owner) which part of the system the answer drives.

### A. The business
1. ★ What is your business called, and what service do you provide? *(branding, entity name)*
2. ★ Do you serve one area with one crew, or several areas/branches? *(one entity vs several; the codebase supports multiple)*
3. ★ Who books the jobs — you, office staff, both? How many dashboard users will there be, and what may each see? *(users + roles: admin / dispatch / finance)*
4. What time zone does the business run in? What days are you closed? *(config timezone, closed days, alert quiet days)*

### B. The jobs
5. ★ Walk me through one typical job, from the first customer contact to money in hand. *(the master question — listen for every module they mention or lack)*
6. ★ What services do you sell, and roughly what does each cost? *(service list, quote ranges)*
7. ★ Where does the work happen — at the customer's place, at your shop, or both? *(address handling, "mobile vs in-shop" flag)*
8. How long does a typical job take? Do you book exact times or arrival windows (like "8–10am")? *(scheduling windows config)*
9. ★ Is each job a one-time visit, or do the same customers repeat on a fixed schedule (weekly, monthly)? *(CRITICAL fit signal — see Phase 2)*
10. What details must be captured about the thing being serviced? (For auto glass it was the vehicle and the exact glass. For a garage door it might be the door model; for a locksmith, lock type.) *(this replaces the vehicle + glass-position fields)*

### C. Materials and parts
11. ★ Do you buy parts or materials for specific jobs? From which suppliers? *(keep or drop the whole parts-ordering module)*
12. Do you pay suppliers on account, or up front per order? *(prepay-tracking flow)*
13. Do workers ever buy materials with their own money and get paid back? *(worker-fronted-parts reimbursement)*

### D. Leads
14. ★ Where do new customers come from — calls, texts, website forms, referral apps, walk-ins? *(intake paths)*
15. What do you write down when a lead comes in? *(intake form fields)*
16. Do leads ever arrive as a pasted block of text in a fixed format (from a call center, a lead service, a WhatsApp group)? If yes, paste me a real example with the personal details changed. *(rebuild the lead parser around their format, or delete it)*

### E. Scheduling and workers
17. ★ Are your workers employees or independent contractors? How many? *(employee records, pay model)*
18. How do you pick which worker gets a job — availability, skill, seniority, location? *(priority ranking; optional AI scheduling)*
19. Do any workers prefer another language for their texts? *(bilingual worker texts — Spanish is already built)*

### F. Worker pay
20. ★ How is a worker paid for a job — flat amount per job, a percentage, hourly, or a payroll salary? *(flat-per-job fits as-is; percentage is a small rework; payroll means the whole pay/settle-up module should be removed)*
21. Do workers collect payment from customers in the field? Including cash? *(cash-balance tracking — who owes what to whom)*
22. When do you settle up with workers — daily, weekly? *(end-of-day settlement flow)*

### G. Customer texting
23. ★ What phone number do customers text or call today, and through which app/provider? *(SMS provider: OpenPhone/Quo and RingCentral are pre-built; others need a small adapter)*
24. ★ Which automatic texts do you want? Read them this list and let them pick: welcome text when a lead comes in · scheduling back-and-forth · booking confirmation · night-before reminder · morning-of reminder · job-done text with payment details · receipt · review request · callback-visit confirmations. *(which template families to keep)*
25. Do some customers prefer Spanish (or another language)? *(customer-side bilingual templates)*

### H. Money
26. ★ How do customers pay you — cash, Zelle, Venmo, check, card, financing, something else? *(payment-method list; card = Stripe payment links, pre-built)*
27. Do you take deposits, or is it pay-in-full? *(partial-payments ledger)*
28. Does an insurance company or a warranty company ever pay for jobs? *(keep or drop the insurance-claims module)*
29. Would you like the tool to watch your bank account and check that every deposit matches a job? (It reads the bank feed; it can never move money.) *(Plaid + bank reconciliation — optional)*

### I. Reviews and callbacks
30. ★ Do you have a Google Business Profile? Would you like the tool to run a review-request system (a quick call to the happy customer, then a text with your review link)? *(review drip)*
31. When a customer calls back about a problem after a job, what happens today? Want that tracked so it never gets lost? *(warranty/callback module)*

### J. Alerts and reports
32. Would a group chat (Telegram) that posts a to-do digest three times a day help — what needs scheduling, what needs ordering, who has not paid? *(Telegram alerts — free, recommended)*
33. Do you run paid ads (Google, etc.)? Want ad spend vs. booked revenue tracked? *(ROAS module — usually drop at first, add later)*
34. What numbers do you look at weekly? (Money owed, worker pay, canceled jobs, revenue?) *(exports/reports to keep)*

### K. Practical
35. ★ What is your comfortable monthly budget for hosting and tools? *(hosting choice — see Phase 5)*
36. What should the tool be called on screen? A short name is fine. *(app name, login page)*

**Closing the interview:** read `BUSINESS_PROFILE.md` back to them as a short plain-language summary ("Here is what I understood…"). Fix anything they correct. Then move to Phase 2.

## Phase 2 — Fit check: adapt this codebase, or rebuild fresh

This codebase assumes a specific shape of business:

- One-time jobs (not recurring visits on a route),
- booked by an office person, dispatched to a worker,
- usually at the customer's location,
- worker paid per job,
- text messages as the main customer channel.

**ADAPT** (keep this codebase, strip the auto-glass parts) when the answers broadly match that shape. Gutter installs, garage doors, locksmiths, junk removal, appliance repair, mobile detailing, handyman — all adapt well.

**REBUILD** (start a fresh Laravel 12 + Filament 4 app, reusing patterns and selected code from this repo) when any of these is core to the business:

- **Recurring route service** (weekly pool/lawn/cleaning rounds) — this app has no recurrence engine; bolting one on is worse than starting clean.
- **Appointment-grid retail** (salon, clinic — customers self-book fixed slots) — different scheduling model entirely.
- **Multi-week projects** with milestones, progress invoices, and change orders (remodeling) — the one-visit job model does not stretch that far.
- **Product/inventory-first** business — this is a service dispatch tool, not a store.
- The worker-pay model is payroll-only AND there is no dispatch — most of the differentiated value would be deleted.

If it is a rebuild: scaffold fresh, but **port these battle-tested pieces rather than rewriting them** — the message outbox + provider layer (`app/Services/Messaging/`), the template system with bilingual rendering and GSM-7 normalization, the Telegram notifier, the settings store with encrypted values, and the roles setup. They are provider-agnostic and hard-won.

**Tell the owner your recommendation in three plain sentences and get an OK before touching code.** Example: "Your business books one-time gutter jobs and sends crews out, which is exactly the shape this tool was built for. I recommend we adapt it. I'll now strip out the auto-glass parts and reshape it for gutters — OK?"

## Phase 3 — The build (adapt path)

Work in **small, committed steps**. After each step: run the tests (`php artisan test` — they use an in-memory database, no setup needed), fix what broke, commit. Never leave the repo broken between sessions. Use `WHATS_INSIDE.md` as the map. Recommended order:

1. **Rename and rebrand.** App name, panel title, login page. Seed their entity/entities (locations) and users with the roles decided in Q3.
2. **Delete the modules the interview ruled out.** Typical first removals, in dependency-safe order:
   - Sales-intake webhook (`SalesIntakeWebhookController`, `SalesIntakeService`) — it received leads from a separate sales app this owner does not have.
   - Mygrant vendor reconciliation (auto-glass supplier billing) — unless they have a supplier with weekly aggregated billing, in which case adapt it.
   - "Xpro-collected" payment methods and the "Owed from Xpro" page — a partner-company arrangement specific to the original business.
   - Insurance module (if Q28 said no).
   - ADAS calibration flow (auto-glass only, always remove).
   - Google Ads ROAS (if Q33 said no — it can return later).
   - When a module goes, remove its scheduled commands from `routes/console.php`, its Filament pages, its tests, and its nav items. **Leave its database columns in place** — unused columns are harmless; dropping them risks breaking things. Note the leftovers in `BUSINESS_PROFILE.md` for a later cleanup.
3. **Reshape the job form.** Replace vehicle year/make/model and glass-position fields with the fields from Q10. Remove the part-number-vs-glass mismatch gate (`PartNumberGlass`) — it is auto-glass logic. Keep the parts/materials repeater if Q11 said yes; keep worker-fronted-parts if Q13 said yes.
4. **Rebuild or remove the lead parser** around their pasted-lead format from Q16. Manual intake always stays.
5. **Rewrite every message template** in the owner's voice, for the texts chosen in Q24, in the languages from Q25. Read the template rules in `CLAUDE.md` first — **plain ASCII punctuation only** (no curly quotes or long dashes; they silently break text delivery), and every merge variable must be a supported one.
6. **Configure scheduling** — arrival windows or exact times (Q8), business hours, closed days, timezone (Q4).
7. **Worker pay** — adjust for Q20. Flat-per-job works as-is (each worker's flat rate on their record). Percentage: change `applyAssignedTechBaseCost` to compute from the quote. Payroll: remove the pay/settlement module and cash balances.
8. **Payment methods** — trim the method list to Q26. Wire Stripe only if wanted.
9. **Review flow and callbacks** — keep/trim per Q30–31; set their Google review link on the entity.
10. **Alerts** — Telegram digests per Q32; trim digest sections to the modules that survived.
11. **Final sweep:** search the whole codebase for `glass`, `windshield`, `vehicle`, `VIN`, `auto`, `tech` (rename to their word for workers if different) and clean up labels the earlier steps missed. Update seeded demo data to their industry.
12. **Update `CLAUDE.md`** so it describes *their* system: their modules, their rules, their vocabulary. Delete stale auto-glass sections. Future Claude sessions depend on this file being true.

Keep the owner in the loop with one short progress note per work session, in plain words: what changed, what is next. Do not narrate code.

## Phase 4 — Try it before hosting

1. Stand the app up in the dev environment (or ask the owner to watch a screen-share is not possible — instead seed realistic demo data and describe screens, or deploy to hosting first and test there; choose what the environment allows).
2. Create one fake customer and run a whole job through: intake → schedule → assign → complete → payment → review request. Show the owner the texts it *would* send (the log provider prints them instead of sending, whenever the app is not in production mode — that is built in).
3. Fix what feels wrong to them. Their words become template edits.

## Phase 5 — Hosting (go live), one step at a time

Recommend by budget (Q35): **Laravel Cloud** (simplest — connects straight to their GitHub, managed database, queue and scheduler built in, ~$20/month) or **Cloudways** (~$15–25/month, more hands-on; this codebase originally ran there, and the notes in `CLAUDE.md` cover its quirks). Walk the owner through with one instruction per message:

1. Create the hosting account; connect GitHub; pick the repo.
2. Set the environment values (`.env`): app key, database, `APP_TIMEZONE=UTC` (**always UTC — the app displays in their local timezone via config; setting the app timezone to local silently shifts every reminder**), queue/cache/session drivers = `database` unless the host provides Redis cleanly.
3. Run migrations and seeders. Create the admin login. **Change every seeded password immediately.**
4. Make sure a **queue worker** and the **scheduler** (every-minute cron) are running — without them, no text ever sends. Laravel Cloud has toggles for both; on Cloudways use Supervisor + cron per `CLAUDE.md`.
5. Connect the texting provider in the dashboard (Admin → Integrations): API key, webhook URL, signing secret. Send a test text to the owner's own phone.
6. Keep outgoing texts **paused** (the built-in switch on the Sending-status page) until the owner has run one real job end-to-end and read every text the system queued. Then unpause. That switch is the go-live moment.
7. Optional connections, each its own short session: Stripe, Telegram bot, Plaid bank feed, Google review links.

## Phase 6 — Handoff

1. Teach the daily flow in one short page (`HOW_WE_USE_IT.md`, written for their office): new lead → schedule → complete → settle up workers → check the digests.
2. Confirm backups exist on the host (daily).
3. Remind them: to change anything later — a text's wording, a new alert, a new report — they just open Claude Code and ask in plain words. This file's job is done; their `CLAUDE.md` now carries the truth.
