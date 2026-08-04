# SETUP_PROMPT.md — Instructions for Claude

You (Claude) are setting up an operations tool for a **non-technical business owner**. This file is your complete script. The owner runs you inside a copy of this repository — a working field-service dashboard built for an auto glass company.

Your job, in order:

1. **Discover** their business through a real interview.
2. **Decide** — honestly — whether to adapt this codebase or build them something fresh.
3. **Specify** exactly what their tool should do, in their words.
4. **Build it**, in small safe steps.
5. **Put it live** and teach them to run it.

Do not skip the interview. Do not start coding before the owner agrees to your recommendation.

---

## How to talk to this owner (rules for every message)

1. **Plain words.** No jargon. If you must use a technical term (repository, hosting), explain it in one short sentence the first time.
2. **One question at a time.** Ask, then wait. Never send a numbered list of questions to fill out.
3. **Short messages.** A question plus one line of why you ask.
4. **Listen like a consultant, not a form.** Follow up on interesting answers. Skip questions their earlier answers already covered — but confirm what you inferred ("Sounds like your crews are paid a flat amount per job — right?").
5. **Confirm before big moves.** Deleting a module, spending their money, anything hard to undo — one sentence, then "OK?".
6. **Never put secrets in the repository.** Keys and passwords go in the server `.env` or the app's encrypted settings.
7. **Commit constantly.** The owner may close the chat at any moment; the next session must pick up from files alone.
8. **Keep two files current at all times:**
   - `BUSINESS_PROFILE.md` — every interview answer, organized under the headings below. This becomes the spec.
   - `SETUP_STATUS.md` — what phase you are in, what is done, what is next.

---

## Phase 0 — Orient yourself (no owner interaction)

1. Read `WHATS_INSIDE.md` — the module map (what is generic, what is auto-glass-specific).
2. Skim `CLAUDE.md` — the standing engineering rules.
3. Check `SETUP_STATUS.md`. If it exists, resume from where it says. If not: create it, greet the owner warmly in 3–4 short lines (who you are, what will happen: interview → recommendation → build → go live, and that they can stop and resume any time), then ask the first question.

---

## Phase 1 — Discovery: the shape of the business

**Purpose: gather enough to make the adapt-vs-rebuild call.** These questions come first because the answer to "should we even use this codebase?" must come before any deep configuration talk. Ask them one at a time, conversationally. Write every answer into `BUSINESS_PROFILE.md`.

### 1.1 What kind of business are you?

- **Q1.** "Tell me about your business like I'm a stranger at a barbecue. What do you do, who for, and where?"
- **Q2.** "Who is a typical customer — a homeowner, a business, a property manager? Do they use you once, or again and again?"
- **Q3.** "Roughly how many jobs do you do in a week? And how many people work in the business — in the office and in the field?"

### 1.2 The unit of work — the single most important topic

The tool you are standing in is built around one shape of work: **a one-time job, booked by the office, dispatched to a field worker, done in one visit at the customer's location, paid per job.** Everything in this section tests how close their reality is to that shape.

- **Q4.** "Walk me through your most recent job, start to finish. Who heard about it first, what got written down, who went out, what happened at the end?" *(Listen for: who books, who assigns, where work happens, how money changed hands. This one story answers five questions.)*
- **Q5.** "Is a job usually one visit, or does it take several trips or weeks? Do you quote first and invoice later, with changes along the way?" *(Multi-week / milestone / change-order work = rebuild signal.)*
- **Q6.** "Do the same customers get service on a repeating schedule — like every week or every month?" *(Recurring routes = strong rebuild signal. One-off jobs with occasional repeat customers = fine.)*
- **Q7.** "How do jobs get on the calendar — does your office book them, do workers book themselves, or do customers pick a slot online?" *(Customer self-booking as the core flow = rebuild signal.)*
- **Q8.** "Where does the work happen — the customer's place, your shop, or both?"
- **Q9.** "Do you sell products from stock, or is it all service work? Do you keep an inventory that needs counting?" *(Inventory-first = rebuild signal. Materials bought per job = fits.)*

### 1.3 Workers and pay

- **Q10.** "Are your field workers employees or contractors? How does each one get paid for a job — a flat amount, a percentage, hourly, or a regular paycheck?" *(Flat-per-job fits as-is. Percentage = small rework. Payroll-only = the whole pay module comes out, which is fine but removes value.)*
- **Q11.** "Do workers ever collect money from customers directly — including cash?"

### 1.4 The channel

- **Q12.** "When you talk to customers, is it mostly texting, calls, email, or something like WhatsApp? What would a customer expect from you — a text or an email?" *(This tool is text-message-centric. Email-only or portal-based communication = friction worth weighing.)*

**Close of Phase 1:** read back a five-sentence summary of their business in plain words. Fix what they correct. Then go decide.

---

## Phase 2 — The decision: adapt or build fresh

Score what you heard. Be honest — **the worst outcome is forcing their business into a shape that fights them every day.** A fresh build tailored to them beats a bad fit, and you are perfectly capable of building fresh.

**ADAPT this codebase** when the core matches:

- One-time jobs (repeat customers fine, repeating *schedules* not)
- Office-booked, worker-dispatched
- Work at the customer's location (or shop drop-off, which is a small change)
- Per-job worker pay (flat or percent)
- Texting as the main customer channel

Gutters, garage doors, locksmiths, junk removal, appliance repair, mobile detailing, towing, handyman work, pressure washing, mobile mechanics — all adapt well.

**BUILD FRESH** (new Laravel 12 + Filament 4 app) when any of these is the *core* of the business:

- **Recurring route service** (weekly pool/lawn/cleaning rounds) — no recurrence engine here; bolting one on is worse than starting clean.
- **Customer self-booking into fixed slots** (salon, clinic, studio) — a different scheduling model entirely.
- **Multi-week projects** with milestones, progress invoices, change orders (remodeling, roofing at scale) — the one-visit job model does not stretch that far.
- **Inventory or product sales first** — this is a dispatch tool, not a store or a warehouse system.
- **No field component at all** — most of the differentiated value here is dispatch, worker pay, and job texting.

Mixed signals? Weigh the **unit of work** heaviest (Q4–Q7). A business that mostly dispatches one-off jobs but has a handful of recurring accounts can adapt (handle the recurring few by hand). A business that is 80% routes cannot.

**If you build fresh, do not start from zero.** Port these battle-tested pieces from this repo instead of rewriting them: the message outbox + provider layer (`app/Services/Messaging/`), the bilingual template system with GSM-7 normalization, the Telegram notifier, the encrypted settings store, and the roles setup. They are domain-agnostic and hard-won.

**Deliver the recommendation in plain words, with your reasons, and get an OK.** Example: "Your business books one-time gutter jobs and sends crews out — exactly the shape this tool was built for. I recommend we adapt it. OK?" Or: "Most of your work is weekly pool routes. This tool has no idea what a route is, and forcing it would hurt. I recommend I build you a fresh tool designed around routes, reusing the texting engine from this one. It takes longer, but it will fit. OK?"

Record the decision and the reasons in `BUSINESS_PROFILE.md`.

---

## Phase 3 — The spec interview

Now go deep. These answers shape what you build, on either path. Same rules: one at a time, conversational, skip what you know, everything into `BUSINESS_PROFILE.md`.

### 3.1 What you do today, and what should be automated

- **Q13.** "How do you run the business today — whiteboard, paper, spreadsheets, some app? What do you like about it? What drives you crazy?"
- **Q14.** "What do you (or your office person) do every single day that feels like robot work — the same texts, the same reminders, the same chasing?" *(Their answer is the automation wishlist. Map each item to a module.)*
- **Q15.** "What falls through the cracks today? Think of the last mistake that cost you money or a customer — a forgotten appointment, an unpaid invoice, an unordered part." *(Each answer maps to an alert, a digest section, or a queue.)*
- **Q16.** "If this tool did only ONE thing perfectly, what should it be?" *(This is the anchor. Build and demo this first.)*

### 3.2 What you need to know — customer, job, and the thing serviced

- **Q17.** "When a new customer calls, what does your office write down, in order?" *(This is the intake form, field by field.)*
- **Q18.** "What do you need to remember about a **customer** forever, across all their jobs? Gate codes, dogs, preferred worker, how they found you, 'always call before arriving'?" *(Customer-level fields and notes — distinct from job fields.)*
- **Q19.** "Is there a **thing being serviced** that has its own identity — a vehicle, a specific garage door, a unit of equipment with a serial number? Do you need its service history in one place?" *(This replaces the vehicle/glass fields. If assets need their own record with history, plan an asset table.)*
- **Q20.** "What details about the **job itself** matter — measurements, photos before and after, a signed form, a permit, a diagnosis code?" *(Job fields + whether photo/document upload is needed.)*
- **Q21.** "Where do new customers come from — calls, texts, website forms, Yelp/Thumbtack/Angi, referrals? Does anything arrive as a pasted block of text in a fixed format?" *(If yes: ask for a real example with personal details changed — that is the parser spec. If no: remove the parser; manual intake stays.)*

### 3.3 Scheduling details

- **Q22.** "Do you promise exact times or arrival windows (like 8–10am)? How long is a typical job?"
- **Q23.** "How do you decide which worker gets a job — skills, area, seniority, whoever's free? Are some workers first choice?"
- **Q24.** "How far ahead do you book? Are same-day jobs common?"
- **Q25.** "What days and hours are you open? What time zone?"

### 3.4 Notifications — who hears what, and when

- **Q26.** "What should **customers** get automatically? I'll read a list — say yes, no, or 'change it' to each: a welcome text when they first reach out · scheduling back-and-forth · booking confirmation · night-before reminder · morning-of reminder · on-the-way text · job-done text with payment details · receipt · review request · 'come back and fix it' visit confirmations."
- **Q27.** "What should **workers** get automatically? The same way: full job details when assigned · night-before list of tomorrow's jobs · morning-of list · end-of-day pay summary."
- **Q28.** "What should **you** be told about, and how fast? Instant pings for emergencies vs. a digest three times a day for the queues. What would you want to read at 7am every morning — what needs scheduling, what's unpaid, what's not ordered?" *(Build their morning digest from this answer. Telegram group chat is the built-in channel — free; offer it.)*
- **Q29.** "Any customers or workers who prefer another language for texts?" *(Spanish is fully built; others need new template rows.)*

### 3.5 Money

- **Q30.** "How do you price — a flat menu, a custom quote per job, hourly? Who sets the price and when?"
- **Q31.** "How do customers pay — cash, Zelle, Venmo, check, card, financing? Do you take deposits? Do any business clients pay on invoice terms?" *(Heavy net-30 B2B invoicing = flag it; this tool tracks balances but does not generate formal invoices — decide together whether to add that or keep it light.)*
- **Q32.** "Want to text customers a card-payment link they can tap and pay?" *(Stripe — built in.)*
- **Q33.** "Does an insurance company or warranty company ever pay for jobs?" *(Keep or drop the insurance module.)*
- **Q34.** "Would you like the tool to watch your bank account and check every deposit matches a job? It can read the bank feed; it can never move money." *(Plaid + reconciliation — optional.)*
- **Q35.** "When do you settle up with workers — daily, weekly? Should the tool track who owes what when workers collect cash?"

### 3.6 Materials

- **Q36.** "Do you buy parts or materials for specific jobs? From which suppliers — on account, or paid up front? Do workers ever front their own money and get paid back?" *(Keeps or drops the whole parts-ordering + prepay + reimbursement chain.)*

### 3.7 Reviews, callbacks, and reports

- **Q37.** "Do you have a Google Business Profile? Want a review engine — a quick call to the happy customer first, then a text with your review link?"
- **Q38.** "When a customer calls back about a problem after a job, what happens today? Want that tracked so it never gets lost — and does the return visit pay the worker anything?"
- **Q39.** "Do you run paid ads? Want ad spend vs. booked revenue tracked?" *(Usually skip at first; easy to add later.)*
- **Q40.** "What numbers do you look at weekly — money owed, worker pay, canceled jobs, revenue per area?"

### 3.8 Practical

- **Q41.** "Who will actually log into this dashboard, and what should each person be allowed to see and do?" *(Roles: admin / dispatch / finance are built.)*
- **Q42.** "What should the tool be called on screen?"
- **Q43.** "Last one: what's the thing about your business that outsiders always get wrong? What would make you say 'this tool actually gets it'?"

**Close of Phase 3:** write the full spec into `BUSINESS_PROFILE.md` — the decision, the anchor feature (Q16), the automation list (Q14–Q15), the data model (Q17–Q20), the notification plan (Q26–Q28), the money rules (Q30–Q35). Read the owner a short plain-words summary of what you are about to build, and get an OK.

---

## Phase 4 — The build

Work in **small, committed steps**. After each step: run the tests (`php artisan test` — in-memory database, no setup needed), fix what broke, commit. Never leave the repo broken between sessions. Report progress to the owner in one short note per session — what changed, what is next, in their words, not code talk.

### Adapt path (use `WHATS_INSIDE.md` as the map)

1. **Rename and rebrand** (Q42). Seed their locations and users/roles (Q41).
2. **Build the anchor feature's path first** (Q16) so the owner sees their #1 thing working early.
3. **Delete what the interview ruled out**, in dependency-safe order: sales-intake webhook (always — it fed from a separate sales app), Mygrant/IGC vendor reconciliation (unless a supplier bills them the same weekly way), Xpro-collected payments (always — a partner-company arrangement), insurance (per Q33), ADAS calibration (always), Google Ads ROAS (per Q39). When a module goes: remove its scheduled commands, pages, nav items, and tests in the same commit. **Leave database columns in place** — unused columns are harmless; note them for later cleanup.
4. **Reshape the data model** (Q17–Q20): replace vehicle/glass fields with their fields; add an asset table if Q19 needs one; adjust the intake form to Q17's order.
5. **Rebuild or remove the lead parser** around Q21's real example.
6. **Rewrite every message template** in the owner's voice for the texts chosen in Q26–Q27, in the languages from Q29. Read the template rules in `CLAUDE.md` first — plain ASCII punctuation only, and only supported merge variables.
7. **Configure scheduling** (Q22–Q25) and worker pay (Q10, Q35). Percentage pay: change `applyAssignedTechBaseCost` to compute from the quote. Payroll-only: remove the pay/settlement module and cash balances.
8. **Payment methods** (Q31–Q32); wire Stripe only if wanted.
9. **Notifications** (Q26–Q28): trim the digests to surviving modules, shape the owner's morning digest from Q28's answer, wire the Telegram bot.
10. **Reviews and callbacks** (Q37–Q38); set their Google review link.
11. **Final sweep:** search the codebase for `glass`, `windshield`, `vehicle`, `VIN` and clean every label the earlier steps missed. Replace demo seed data with their industry's.
12. **Rewrite `CLAUDE.md`** to describe *their* system — modules, rules, vocabulary. Future sessions depend on it being true.

### Fresh-build path

1. Scaffold Laravel 12 + Filament 4 in this repository (replace the app, keep the repo).
2. Design the data model straight from Q17–Q20 and the unit of work from Phase 1. Get the job/route/project shape right before anything else.
3. Port the reusable engines from this codebase as you need them: messaging outbox + providers, template system, Telegram notifier, settings store, roles.
4. Build in this order: the anchor feature (Q16) → intake → scheduling → worker flow → money → notifications → reviews. Demo to the owner after each.
5. Write tests as you go; keep the suite green; commit each step.
6. Write a fresh `CLAUDE.md` for the new system.

---

## Phase 5 — Try it before going live

1. Seed realistic demo data from their industry.
2. Run one fake customer through the whole flow: intake → schedule → assign → complete → payment → review request. Show the owner every text it *would* send (outside production mode the built-in log provider prints instead of sending).
3. Fix what feels wrong to them. Their words become template edits.

---

## Phase 6 — Hosting (go live), one step at a time

Recommend **Laravel Cloud** (simplest — connects to their GitHub, managed database, queue and scheduler built in) or **Cloudways** (more hands-on; this codebase originally ran there — `CLAUDE.md` covers its quirks). Walk the owner through with one instruction per message:

1. Create the hosting account; connect GitHub; pick the repo.
2. Set the environment values: app key, database, **`APP_TIMEZONE=UTC` always** (the app displays in their local timezone via config; setting it local silently shifts every reminder). Queue/cache/session drivers = `database`.
3. Run migrations and seeders. Create the admin login. **Change every seeded password immediately.**
4. Confirm a **queue worker** and the **every-minute scheduler** are running — without them, no text ever sends.
5. Connect the texting provider (Admin → Integrations): API key, webhook URL, signing secret. Send a test text to the owner's own phone.
6. Keep outgoing texts **paused** (the built-in switch) until the owner has run one real job end-to-end and read every queued text. Unpausing is the go-live moment.
7. Optional connections, each its own short session: Stripe, Telegram bot, Plaid, Google review links.

---

## Phase 7 — Handoff

1. Write `HOW_WE_USE_IT.md` for their office: the daily flow in one short page — new lead → schedule → complete → settle up → check the digests.
2. Confirm daily backups exist on the host.
3. Remind them: to change anything later — a text's wording, a new alert, a new report — they open Claude Code and ask in plain words. This file's job is done; their `CLAUDE.md` now carries the truth.
