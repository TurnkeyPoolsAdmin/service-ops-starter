# What's Inside — Module Map

A guide for the AI assistant adapting this codebase to a new business. Three groups: **generic** (keep — works for any field-service business), **industry-specific** (auto-glass only — remove or heavily rework), and **mixed** (generic skeleton with auto-glass fields woven in).

The original business: a multi-location mobile auto glass company. "Tech" = the field worker. "Entity" = a business location/brand. Demo seed data still uses auto-glass names — replace it all during setup.

## Generic modules (keep)

| Module | Purpose | Key paths | Tables |
|---|---|---|---|
| Job lifecycle & board | Job CRUD, status machine, schedule/cancel/complete/lost | `app/Services/JobLifecycle.php`, `app/Filament/Resources/Jobs/*`, `app/Enums/JobStatus.php` | `jobs` |
| Scheduling calendar | Calendar view, business hours, closed days | `app/Filament/Pages/JobCalendar.php`, `app/Support/{BusinessDays,BusinessHours,ClosedDays}.php` | `holidays` |
| Customers | Contact record, opt-out, language preference | `app/Models/Customer.php` | `customers` |
| SMS outbox + providers | Outbound text queue; pluggable providers (Log, OpenPhone/Quo, Podium, RingCentral) | `app/Services/Messaging/*`, `app/Jobs/SendSmsJob.php`, `app/Http/Controllers/Webhooks/*` | `scheduled_messages`, `inbound_messages` |
| Message templates | Keyed, per-language bodies with merge variables; GSM-7 safe | `app/Models/MessageTemplate.php`, `app/Services/Messaging/TemplateRenderer.php`, `app/Enums/TemplateKey.php` | `message_templates` |
| Reminders | Night-before / morning-of texts, customer + worker digests | `app/Services/Messaging/{ReminderScheduler,TechReminderScheduler,TechEodSummaryScheduler}.php` | — |
| Review drip | Call-first review request sequence | `app/Services/Reviews/ReviewDripService.php`, `app/Models/ReviewRequest.php` | `review_requests` |
| Warranty / callbacks | "Come back and fix it" visit tracking | `app/Services/Warranty/WarrantyService.php`, `app/Models/WarrantyClaim.php` | `warranty_claims` |
| Worker pay + settlement | Per-job pay, end-of-day settle-up, cash balances | `JobLifecycle` settle methods, `app/Models/TechSettlement.php`, `app/Filament/Pages/{TechPayments,TechSettlements}.php` | `tech_settlements` |
| Partial payments | Multi-payment ledger per job | `app/Models/JobPayment.php` | `job_payments` |
| Stripe | Card payment links, webhook auto-confirm, payout reconciliation | `app/Services/Stripe/*`, `StripeWebhookController` | `stripe_payouts*` |
| Plaid bank feed + matcher | Bank transaction sync and auto-matching to jobs/payouts | `app/Services/Plaid/PlaidClient.php`, `app/Services/BankMatcher.php` | `bank_*` |
| Telegram alerts | Group-chat digests + alerts, quiet hours, silent days | `app/Services/Telegram/*`, `app/Support/AlertWindow.php` | — |
| Roles & permissions | Spatie RBAC; admin/dispatch/finance roles | `database/seeders/RoleSeeder.php` | `roles`, `permissions` |
| Job notes + timeline | CRM-style note feed, activity timeline | `app/Models/JobNote.php`, `.../Support/{JobNotesFeed,JobTimeline}.php` | `job_notes` |
| Job search | One search rule for the board + global search | `app/Support/JobSearch.php` | — |
| Issues sweep | Condition-based exception queue (hourly recompute) | `app/Console/Commands/SweepJobIssues.php`, `app/Enums/IssueType.php` | `issues` |
| Open tasks | "Still needs a human" rollup page | `app/Filament/Pages/OpenTasks.php` | — |
| Scheduling hold | "Waiting to hear back from customer" state | `Job::scopeOnSchedulingHold`, hold actions in `JobActions` | — |
| Data exports | CSV reports (cancellations, worker labor) | `app/Services/Reporting/*`, `app/Support/Csv.php` | — |
| AI scheduling | Texts workers a slot, reads replies with an LLM, negotiates, books | `app/Services/AiScheduling/*` | `ai_scheduling_*` |
| Connector health | Hourly probe of every integration, alert on break | `app/Services/Health/ConnectorHealthChecker.php` | `connector_health` |
| Integrations hub | Settings pages per provider; encrypted settings store | `app/Filament/Pages/Integrations/*`, `app/Models/Setting.php` | `settings` |
| Receipts | On-request receipt text/PDF | `app/Services/Receipts/*` | — |

## Industry-specific modules (remove or rework)

| Module | What it is | Where | Removal touches |
|---|---|---|---|
| Glass position + part-number logic | NAGS part-number parsing, part-vs-glass mismatch gate | `app/Support/{GlassPosition,PartNumber,PartNumberGlass}.php` | `jobs.glass_position/part_number/part_glass_override_*`, `JobForm`, `JobActions`, `SweepJobIssues`, `IssueType::PartGlassMismatch` |
| ADAS calibration | Windshield-camera calibration partner pipeline | `app/Services/Calibration/*`, `app/Filament/Pages/CalibrationJobs.php`, `app/Enums/CalibrationStatus.php` | `jobs.calibration_*` columns, `calibration` role, OpenTasks entry, two Telegram alerts |
| Vehicle fields | Year/make/model/VIN on jobs | `JobForm` Summary section, `Job::vehicleLabel()`, `JobSearch` | Replace with this business's "thing being serviced" fields |
| Mygrant + IGC vendor reconciliation | Two glass suppliers' weekly billing-cycle reconciliation | `app/Services/Vendors/*`, `app/Filament/Pages/PartsInvoices.php`, `app/Models/Vendor*` | `config/glass.php` `vendors` block, ~10 scheduled commands, 3 `IssueType` cases, `BankMatcher::looksLikePartsVendor` |
| Xpro-collected payments | A partner company collecting payments on our behalf | `app/Filament/Pages/OwedFromXpro.php`, `Job::xpro*` methods, 2 Telegram alerts | `PaymentMethod` "- Xpro Team" cases, `IssueType::XproUncollected`, 2 weekly schedules |
| Insurance flow | Customer pays deductible; carrier pays separately | `app/Filament/Pages/InsuranceJobs.php`, `jobs.insurance_*`, `JobLifecycle::recordInsurancePayment` | Keep only if this business bills insurers |
| WhatsApp lead parser | Parses the original sales team's pasted lead format | `app/Services/Parsing/WhatsAppLeadParser.php` (1000+ lines) | Rebuild for the new lead format, or remove (manual intake stays) |
| Sales-intake webhook | Receives booked leads from the owner's separate sales app | `SalesIntakeWebhookController`, `app/Services/Intake/SalesIntakeService.php` | Remove unless they have a separate intake system |
| Google Ads ROAS | Ad spend vs booked revenue per location | `app/Services/Ads/*`, `app/Services/Reporting/Roas*`, `app/Models/AdMetric.php` | `view_roas` permission, daily pull schedule |
| Seeded copy + demo entities | 58 template bodies in auto-glass voice; demo locations | `database/seeders/{EntitySeeder,MessageTemplateSeeder,WarehouseSeeder}.php` | Rewrite all copy; reseed entities |

## Mixed modules (generic core, glass fields woven in)

- **`app/Models/Job.php`** (~1400 lines) + **`JobForm`/`JobInfolist`/`JobsTable`/`JobActions`** — the lifecycle, scheduling, payment, and notes parts are generic. Glass-specific: vehicle columns, `glass_position`, `part_number`, calibration columns/methods, insurance columns/methods, xpro methods, part-glass-mismatch methods. Strip in place; do not rewrite the class.
- **Parts ordering** (`app/Filament/Pages/PartsToOrder.php`, ordered/prepaid stamps, `labor_only` flag) — the "materials must be ordered before the visit" concept is generic; the supplier links (`app/Services/Parts/PartsOrderLinks.php`) are glass vendors. Keep the module if the business orders materials per job; otherwise remove it and its digest sections.
- **`Warehouse`** — generic "where materials come from," but `supplier` casts to a glass-vendor enum (`PartsSupplier`).
- **`IssueType`** — generic mechanism; remove the 5 glass/vendor cases, keep the rest.
- **`Entity`** — generic multi-location record; `google_ads_customer_id` belongs to ROAS.
- **`config/glass.php`** — most keys generic; `vendors.*`, `parts_ordering.*`, `google_ads` are glass-specific. `bank_matching.parts_vendors` and `dividend_recipients` are business data to replace.

## Scheduled commands (routes/console.php)

Generic (keep): `messages:process-due` (every min), `reviews:advance`, `alerts:sms-delivery-unconfirmed`, `reminders:tech-digests`, `tech:eod-summaries`, `bank:pull-transactions`, `bank:suggest-matches`, `stripe:match-payouts`, `stripe:resolve-payouts`, `alerts:telegram-digest` (3×/day), `alerts:telegram-overdue-jobs`, `alerts:telegram-warranty-overdue`, `alerts:telegram-review-calls`, `issues:sweep`, `connectors:health-check`, `ai:advance-sessions`.

Industry-specific (remove with their modules): `ads:pull-metrics`, `mygrant:reconcile`, `igc:reconcile`, all `alerts:telegram-mygrant-*` / `alerts:telegram-igc-*`, `alerts:telegram-xpro-*`, `alerts:telegram-same-day-parts-unordered` (keep only if materials-per-job stays).

## Notes for the adapting AI

- Tests: ~115 feature test files. When you remove a module, remove its tests in the same commit. Run `php artisan test` after every step.
- The word "tech" (technician) is used throughout code and UI. Renaming the concept in the **UI labels** is cheap; renaming code symbols is not worth it.
- Migrations reference glass columns; leave old migrations and columns alone — remove UI/logic instead. New installs run the full chain harmlessly.
- Demo seed data (entities "Auto Glass of LA" etc., warehouse names, employee names) is intentionally left as example data — replace it during setup.
